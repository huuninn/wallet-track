<?php

declare(strict_types=1);

namespace App\Dto;

use App\Services\Telegram\TelegramFileDownloader;

/**
 * DTO imutável representando um arquivo baixado do Telegram (M4).
 *
 * Contém o conteúdo da imagem em base64 (pronto para envio ao Gemini), o
 * mimeType detectado e o tamanho em bytes. Instâncias são produzidas por
 * implementações de {@see TelegramFileDownloader}.
 */
final readonly class TelegramFile
{
    public function __construct(
        /** Conteúdo do arquivo codificado em base64 (string pura, sem prefixo data URI). */
        public string $base64,
        /** MIME type detectado do conteúdo (ex.: "image/jpeg"). */
        public string $mimeType,
        /** Tamanho do arquivo em bytes (do conteúdo bruto, antes do base64). */
        public int $size,
    ) {}
}
