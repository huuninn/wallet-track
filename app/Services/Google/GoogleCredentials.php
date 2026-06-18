<?php

declare(strict_types=1);

namespace App\Services\Google;

use RuntimeException;

/**
 * Resolve o array `keyFile` (service account) a partir das duas fontes
 * suportadas por `config('google.*')`.
 *
 *  1. Conteúdo inline via `service_account_json` (prioridade — caminho de
 *     produção, M10). Pode ser JSON cru ou base64 do JSON (Secret Manager
 *     às vezes entrega base64 para evitar problemas de aspas).
 *
 *  2. Caminho de arquivo via `service_account_json_path` (caminho de dev).
 *     Lê e decodifica o `.json` da service account do disco.
 *
 * Se ambos estiverem vazios, lança {@see RuntimeException} — quem instanciar
 * o cliente Google sem keyFile deve falhar cedo e com mensagem clara.
 *
 * Esta é a abstração que o M10 (Secret Manager) completa: em produção,
 * injetaremos o conteúdo JSON via variável de ambiente ou via fetch do
 * Secret Manager em runtime. Aqui em M5 apenas normalizamos o conteúdo
 * recebido em array PHP pronto para `FirestoreClient(['keyFile' => $array])`.
 */
final class GoogleCredentials
{
    /**
     * @param  array<string, mixed>  $config  Slice de config('google') com as
     *                                        chaves 'service_account_json' e
     *                                        'service_account_json_path'.
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /**
     * Cria a instância a partir do config('google') padrão do Laravel.
     */
    public static function fromConfig(?array $config = null): self
    {
        return new self($config ?? config('google', []));
    }

    /**
     * Resolve e devolve o array `keyFile` no formato aceito pelo SDK do Google.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException Se nenhuma fonte de credencial está disponível,
     *                          ou se o JSON decodificado não é um array válido.
     */
    public function resolveKeyFile(): array
    {
        $inline = $this->config['service_account_json'] ?? null;
        $path = $this->config['service_account_json_path'] ?? null;

        if (is_string($inline) && trim($inline) !== '') {
            return $this->decodeJsonContent($inline, source: 'GOOGLE_SERVICE_ACCOUNT_JSON');
        }

        if (is_string($path) && trim($path) !== '') {
            if (! is_readable($path)) {
                throw new RuntimeException(
                    "GOOGLE_SERVICE_ACCOUNT_JSON_PATH aponta para arquivo inacessível: {$path}",
                );
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException(
                    "Falha ao ler GOOGLE_SERVICE_ACCOUNT_JSON_PATH: {$path}",
                );
            }

            return $this->decodeJsonContent($contents, source: "arquivo {$path}");
        }

        throw new RuntimeException(
            'Credenciais Google ausentes: defina GOOGLE_SERVICE_ACCOUNT_JSON (conteúdo JSON, '
            .'preferencial em produção) ou GOOGLE_SERVICE_ACCOUNT_JSON_PATH (caminho do arquivo, '
            .'preferencial em desenvolvimento).',
        );
    }

    /**
     * Decodifica o conteúdo JSON, detectando e tratando base64.
     *
     * Heurística base64: se o conteúdo não começa com "{" (JSON cru), tenta
     * decodificar como base64 estrito. Se o resultado decodificado for um
     * JSON válido, usa-o. Caso contrário, falha com mensagem clara.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function decodeJsonContent(string $content, string $source): array
    {
        $content = trim($content);

        // JSON cru já vem começando com "{".
        if (! str_starts_with($content, '{')) {
            $decoded = base64_decode($content, strict: true);

            if ($decoded === false || ! str_starts_with(trim($decoded), '{')) {
                throw new RuntimeException(
                    "Conteúdo de credencial Google em {$source} não é JSON válido nem base64 de JSON.",
                );
            }

            $content = $decoded;
        }

        /** @var mixed $data */
        $data = json_decode($content, true);

        if (! is_array($data)) {
            throw new RuntimeException(
                "Credencial Google em {$source} não decodifica para um array JSON (json_last_error_msg: "
                .(json_last_error_msg() ?: 'desconhecido').').',
            );
        }

        return $data;
    }
}
