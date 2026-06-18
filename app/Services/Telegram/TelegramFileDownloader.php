<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Actions\ExtractFromImage;
use App\Dto\TelegramFile;

/**
 * Abstração sobre o download de arquivos do Telegram (M4).
 *
 * Isolar o download por trás de uma interface permite que a Action
 * {@see ExtractFromImage} seja testada com um
 * FakeTelegramFileDownloader (stub que devolve base64 fixo), sem realizar
 * chamadas reais à API do Telegram — requisito dos testes do M4.
 *
 * O contrato recebe o file_id (identificador Telegram) e devolve um
 * {@see TelegramFile} com a imagem pronta para envio ao Gemini.
 */
interface TelegramFileDownloader
{
    /**
     * Baixa o arquivo do Telegram pelo file_id.
     *
     * @param  string  $fileId  Identificador do arquivo no Telegram.
     * @return TelegramFile Conteúdo base64 + mimeType + tamanho.
     *
     * @throws \RuntimeException Em falha de download (arquivo indisponível,
     *                           rede, arquivo grande demais — bots baixam até 20MB).
     */
    public function download(string $fileId): TelegramFile;
}
