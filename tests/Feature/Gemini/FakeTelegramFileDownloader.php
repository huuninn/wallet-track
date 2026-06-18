<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Dto\TelegramFile;
use App\Services\Telegram\TelegramFileDownloader;

/**
 * Stub de teste que implementa {@see TelegramFileDownloader} sem tocar na
 * API do Telegram.
 *
 * Usado por {@see ExtractFromImageTest} para simular o download de uma
 * imagem (devolve base64 + mimeType fixos pré-setados). Garante que os
 * testes da Action sejam determinísticos e viáveis em CI sem internet.
 */
final class FakeTelegramFileDownloader implements TelegramFileDownloader
{
    public function __construct(
        private readonly string $base64 = 'iVBORw0KGgo=',
        private readonly string $mimeType = 'image/jpeg',
        private readonly int $size = 1024,
    ) {}

    public function download(string $fileId): TelegramFile
    {
        return new TelegramFile(
            base64: $this->base64,
            mimeType: $this->mimeType,
            size: $this->size,
        );
    }
}
