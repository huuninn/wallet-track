<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Dto\TelegramFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Media\File as TelegramFileObject;
use Throwable;

/**
 * Implementação concreta de {@see TelegramFileDownloader} sobre o Nutgram.
 *
 * Fluxo:
 *  1. `$bot->getFile($fileId)` → obtém metadados do arquivo (file_path, size).
 *  2. `$bot->downloadUrl($file)` → monta a URL
 *     `https://api.telegram.org/file/bot<token>/<file_path>`.
 *  3. Baixa os bytes via HTTP client do Laravel (Guzzle por baixo).
 *  4. Detecta o MIME type do Content-Type da resposta (fallback: extensão).
 *  5. base64-encode → devolve {@see TelegramFile}.
 *
 * Esta classe é registrada como singleton no GeminiServiceProvider. Em
 * testes, ela nunca é instanciada — bindamos um FakeTelegramFileDownloader.
 *
 * Nota: bots do Telegram só conseguem baixar arquivos de até 20MB via getFile
 * (limite da Bot API). Fotos enviadas pelos usuários sempre estão abaixo disso.
 */
final class NutgramFileDownloader implements TelegramFileDownloader
{
    public function __construct(
        private readonly Nutgram $bot,
    ) {}

    public function download(string $fileId): TelegramFile
    {
        try {
            $file = $this->bot->getFile($fileId);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Falha ao obter metadados do arquivo Telegram (file_id: {$fileId}).",
                previous: $e,
            );
        }

        if ($file === null || $file->file_path === null) {
            throw new RuntimeException(
                "Arquivo Telegram não encontrado ou sem file_path (file_id: {$fileId}).",
            );
        }

        $url = $this->bot->downloadUrl($file);

        if ($url === null) {
            throw new RuntimeException(
                "Não foi possível montar a URL de download do arquivo Telegram (file_id: {$fileId}).",
            );
        }

        try {
            $response = Http::timeout(60)->get($url);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Falha de rede ao baixar arquivo Telegram (file_id: {$fileId}).",
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "Download do arquivo Telegram falhou com HTTP {$response->status()} (file_id: {$fileId}).",
            );
        }

        $bytes = $response->body();

        if ($bytes === '') {
            throw new RuntimeException(
                "Download do arquivo Telegram retornou corpo vazio (file_id: {$fileId}).",
            );
        }

        $mimeType = $this->detectMimeType($response->header('Content-Type'), $file);
        $size = $file->file_size ?? strlen($bytes);

        return new TelegramFile(
            base64: base64_encode($bytes),
            mimeType: $mimeType,
            size: $size,
        );
    }

    /**
     * Detecta o MIME type da imagem. Prioriza o Content-Type da resposta HTTP;
     * se ausente ou genérico, infere da extensão do file_path do Telegram.
     */
    private function detectMimeType(string $contentType, TelegramFileObject $file): string
    {
        $contentType = strtolower(trim($contentType));
        // Remove parâmetros como "; charset=..." se presentes.
        $contentType = $contentType !== '' ? trim(explode(';', $contentType)[0]) : '';

        $imageMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

        if (in_array($contentType, $imageMimes, true)) {
            return $contentType;
        }

        // Fallback: infere da extensão do file_path (ex.: "photos/file_123.jpg").
        $extension = strtolower(pathinfo($file->file_path ?? '', PATHINFO_EXTENSION));

        // default NÃO força image/jpeg: isso mascararia formatos não suportados
        // (ex.: GIF) e os enviaria ao Gemini com tipo errado → falha obscura.
        // Devolvemos o Content-Type HTTP original (ou application/octet-stream
        // se vazio) para que GeminiService::validateInput() o rejeite com
        // INVALID_INPUT de forma explícita e rastreável.
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            default => $contentType !== '' ? $contentType : 'application/octet-stream',
        };
    }
}
