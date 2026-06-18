<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Valida a origem e o remetente do webhook do Telegram.
 *
 * Três camadas de segurança aplicadas em ordem (barreira mais barata e mais
 * externa primeiro — falha cedo para reduzir superfície de processamento):
 *
 *  1. Secret token (header X-Telegram-Bot-Api-Secret-Token): comparação
 *     timing-safe via hash_equals() contra config('telegram.webhook_secret_token').
 *     Rejeita com 401 se faltante ou divergente. Fail-closed se o config
 *     estiver vazio (misconfig → Log::critical + 401; não abre exceção por
 *     conveniência).
 *
 *  2. Whitelist de chat_id: extrai o identificador do remetente do payload
 *     (message.from.id | callback_query.from.id | edited_message.from.id |
 *     channel_post.from.id | edited_channel_post.from.id) e valida contra
 *     config('telegram.allowed_chat_ids') com in_array(strict=true).
 *     Rejeita com 403 se não-autorizado ou se o remetente não puder ser
 *     identificado (update exótico — fail-closed).
 *
 *  3. Logging: toda tentativa bloqueada gera Log::warning com reason,
 *     chat_id (quando aplicável) e ip. NUNCA logar o valor do secret token
 *     nem o payload completo (PII / presente para atacantes).
 *
 * ⚠️ TENSAO DE DESIGN — RETRY STORM DO TELEGRAM:
 *
 * O Telegram reenvia updates cujo webhook retorna status não-2xx, com
 * backoff exponencial. Implicações por status code:
 *
 *  - 401 (secret token ausente/inválido): a requisição NÃO veio do Telegram
 *    (só quem conhece o secret é o próprio Telegram). É um ataque direto ao
 *    endpoint, então 401 é seguro — o Telegram não vai reenviar o que não
 *    enviou. ✅ Sem problema.
 *
 *  - 403 (chat_id não-autorizado): a requisição VEIO do Telegram (o secret
 *    era válido) — retornar 403 fará o Telegram REENVIAR a mesma update
 *    repetidamente, configurando risco teórico de "retry storm" se um
 *    usuário não-autorizado floodar o bot com mensagens.
 *
 * Para um bot pessoal com 1 único usuário autorizado (whitelist = {5672987197}),
 * a chance real de alguém não-autorizado enviar mensagens é praticamente zero,
 * então a política 403 está implementada conforme a especificação M2.
 *
 * 🔒 SE O BOT ALGUM DIA TIVER MÚLTIPLOS USUÁRIOS OU FOR PÚBLICO:
 *    a resposta para chat_id não-autorizado deveria mudar para 200 OK +
 *    drop silencioso (sem chamar o controller), evitando o retry storm.
 *    Reavaliar esta política quando o cenário de uso mudar.
 *
 * Body-reading: o middleware lê o payload via $request->json(...) para extrair
 * o chat_id. Em produção (FrankenPHP/Octane), o php://input permanece legível
 * para o running mode Webhook do Nutgram, que o lê diretamente em run().
 * Em testes, o Nutgram é mockado — sem impacto.
 */
class ValidateTelegramWebhook
{
    /**
     * Tipos de update do Telegram onde o remetente pode ser identificado,
     * em ordem de frequência. O middleware extrai `from.id` do primeiro match.
     *
     * Fonte: https://core.telegram.org/bots/api#update
     */
    private const SENDER_PATHS = [
        'message',
        'callback_query',
        'edited_message',
        'channel_post',
        'edited_channel_post',
    ];

    /**
     * Razões de bloqueio registradas no log — estabiliza o vocabulário para
     * dashboards/alertas no Cloud Logging.
     */
    private const REASON_MISSING_SECRET = 'missing_secret_token';

    private const REASON_INVALID_SECRET = 'invalid_secret_token';

    private const REASON_CHAT_NOT_ALLOWED = 'chat_id_not_allowed';

    private const REASON_UNIDENTIFIABLE = 'unidentifiable_sender';

    public function handle(Request $request, Closure $next): mixed
    {
        // 1) Secret token — barreira externa (rejeita qualquer coisa que não
        //    tenha vindo dos servidores do Telegram). Mais barata porque não
        //    exige parse do body.
        if (! $this->secretTokenIsValid($request)) {
            // secretTokenIsValid() já registrou o motivo (missing | invalid |
            // critical misconfig) — apenas retorna a resposta genérica.
            return $this->unauthorized();
        }

        // 2) Whitelist de chat_id — barreira interna. Só roda se o secret
        //    passou (requisição efetivamente veio do Telegram).
        $chatId = $this->extractSenderChatId($request);

        if ($chatId === null) {
            // Update sem remetente identificável (ex.: poll, chat_member,
            // poll_answer sem user). Fail-closed: não processa updates que
            // não consegue atribuir a um usuário.
            $this->logBlocked(self::REASON_UNIDENTIFIABLE, null, $request);

            return $this->forbidden();
        }

        if (! $this->chatIdIsAllowed($chatId)) {
            $this->logBlocked(self::REASON_CHAT_NOT_ALLOWED, $chatId, $request);

            return $this->forbidden();
        }

        return $next($request);
    }

    /**
     * Compara o header recebido com o secret esperado de forma timing-safe.
     * Fail-closed: secret vazio no .env (misconfig) rejeita tudo e emite
     * Log::critical para o dono detectar a configuração incorreta.
     *
     * @unstable Recebe $request apenas para extrair IP e header.
     */
    private function secretTokenIsValid(Request $request): bool
    {
        $expected = (string) config('telegram.webhook_secret_token');

        if ($expected === '') {
            // Misconfig crítica — fail-closed. Não abrir exceção de segurança
            // por conveniência: se o secret não está configurado, ninguém
            // deveria conseguir chamar o webhook. O dono precisa ver o
            // critical no log e configurar antes de seguir.
            // ⚠️ Não inclui o IP/contexto aqui para deixar o log crítico
            // focado na misconfig (não é uma tentativa de ataque isolada).
            Log::critical('Telegram webhook: TELEGRAM_WEBHOOK_SECRET_TOKEN não configurado — rejeitando todas as requisições (fail-closed).');

            return false;
        }

        // $request->headers->get() nunca lança e sempre retorna string|null.
        // O cast garante string estável para o hash_equals.
        $received = (string) $request->headers->get('X-Telegram-Bot-Api-Secret-Token', '');

        if ($received === '') {
            $this->logBlocked(self::REASON_MISSING_SECRET, null, $request);

            return false;
        }

        // hash_equals: comparação timing-safe para evitar timing attacks.
        // NUNCA usar === ou != para comparar secrets.
        if (! hash_equals($expected, $received)) {
            $this->logBlocked(self::REASON_INVALID_SECRET, null, $request);

            return false;
        }

        return true;
    }

    /**
     * Extrai o chat_id do remetente do update, percorrendo os tipos mais
     * comuns. Retorna null quando o payload não tem remetente identificável
     * (ex.: poll, chat_member).
     *
     * O Telegram envia `from.id` como Integer; o decode do Laravel preserva
     * o tipo. is_int() protege contra payloads malformados (string/float).
     */
    private function extractSenderChatId(Request $request): ?int
    {
        foreach (self::SENDER_PATHS as $path) {
            $id = $request->json("{$path}.from.id");

            if (is_int($id) && $id > 0) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Verifica se o chat_id está na whitelist. Comparação estrita (int) —
     * whitelist vazia = fail-closed (tudo rejeitado). O
     * TelegramServiceProvider::boot() já avisa sobre whitelist vazia na
     * inicialização; aqui apenas executamos a política.
     */
    private function chatIdIsAllowed(int $chatId): bool
    {
        $allowed = config('telegram.allowed_chat_ids');

        if (! is_array($allowed) || $allowed === []) {
            return false;
        }

        return in_array($chatId, $allowed, strict: true);
    }

    /**
     * Loga uma tentativa bloqueada em nível warning.
     *
     * ⚠️ NUNCA incluir nestes logs:
     *  - o valor do secret token (recebido ou esperado);
     *  - o payload completo do update;
     *  - dados PII do usuário (nome, username, conteúdo da mensagem);
     *  - qualquer detalhe que ajude um atacante a fingerprintear a validação.
     *
     * A resposta HTTP sempre carrega apenas `{'error': '...'}` genérico.
     */
    private function logBlocked(string $reason, ?int $chatId, Request $request): void
    {
        Log::warning('Telegram webhook: requisição bloqueada', [
            'reason' => $reason,
            'chat_id' => $chatId,
            'ip' => $request->ip(),
        ]);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['error' => 'unauthorized'], 401);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['error' => 'forbidden'], 403);
    }
}
