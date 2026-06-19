<?php

declare(strict_types=1);

/*

|--------------------------------------------------------------------------
| Conversa (máquina de estados do bot — M7)
|--------------------------------------------------------------------------
|
| Parâmetros de runtime da camada conversacional ({@see \App\Conversation\ConversationRouter}):
|
|  - timeout_minutes: janela de expiração da sessão (sessions/{chat_id}).
|    Se `updated_at` for mais antigo que este limite, a próxima interação
|    do usuário trata a sessão como expirada (M7.8): limpa, informa o
|    usuário e reinicia o fluxo a partir de IDLE.
|
|  - max_data_retries: limite de retentativas ao pedir um campo pedível
|    (valor/tipo/data). Acima deste limite, o bot desiste com mensagem
|    amigável em vez de loopar infinito (defesa contra usuário preso no
|    estado AWAITING_DATA).
|
| O valor default de 15 minutos reflete o padrão de bots de messaging
| (sessões curtas evitam confusão do usuário ao retomar horas depois).
|
*/

return [

    'timeout_minutes' => (int) env('SESSION_TIMEOUT_MINUTES', 15),

    'max_data_retries' => (int) env('SESSION_MAX_DATA_RETRIES', 3),

];
