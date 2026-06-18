<?php

declare(strict_types=1);

namespace App\Services\DeepSeek;

use App\Exceptions\ExtractionException;

/**
 * Abstração sobre o cliente de chat completion (DeepSeek / OpenAI-compatível).
 *
 * Isolar esta chamada por trás de uma interface permite que a
 * {@see DeepSeekService} seja testada com um
 * FakeChatCompleter (stub que devolve JSON de fixture), sem realizar
 * chamadas reais à API — requisito dos testes do M3, pois os containers
 * de CI não têm acesso à internet.
 *
 * O contrato recebe o prompt do sistema e as mensagens do usuário já no
 * formato esperado pela API de chat, centralizando a montagem do client
 * (api_key, base_url, model) na implementação concreta.
 */
interface ChatCompleter
{
    /**
     * Executa uma completion de chat e devolve o conteúdo da primeira escolha.
     *
     * @param  string  $systemPrompt  Prompt do sistema (instruções de extração).
     * @param  array<int, array{role: string, content: string}>  $userMessages
     *                                                                          Mensagens da conversa no formato OpenAI
     *                                                                          (ex.: `[['role' => 'user', 'content' => 'Paguei R$ 47,50 no almoço']]`).
     *                                                                          Para M3 é sempre uma única mensagem de usuário; múltiplas mensagens
     *                                                                          (multi-turn) serão usadas em M5.
     * @param  array<string, mixed>  $options  Sobrescritas pontuais
     *                                         (ex.: `['model' => '...', 'temperature' => 0.1]`).
     * @return string Conteúdo textual da resposta (esperado: JSON estrito).
     *
     * @throws ExtractionException Em falha de comunicação/timeout
     *                             (reason = API_ERROR) — a implementação concreta encapsula erros brutos.
     */
    public function complete(string $systemPrompt, array $userMessages, array $options = []): string;
}
