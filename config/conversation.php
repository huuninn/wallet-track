<?php

declare(strict_types=1);

/*

|--------------------------------------------------------------------------
| Conversa (máquina de estados do bot)
|--------------------------------------------------------------------------
|
| Parâmetros de runtime da camada conversacional ({@see \App\Conversation\ConversationRouter}):
|
|  - max_data_retries: limite de retentativas ao pedir um campo pedível
|    (valor/tipo/data). Acima deste limite, o bot desiste com mensagem
|    amigável em vez de loopar infinitamente (defesa contra usuário preso no
|    estado AWAITING_DATA).
|
| A expiração de sessão é controlada pelo TTL do Redis (HSET com expire),
| não mais por um campo de configuração — ver {@see \App\Services\Store\WalletStore::setSession()}.
|
*/

return [

    'max_data_retries' => (int) env('SESSION_MAX_DATA_RETRIES', 3),

];
