<?php
declare(strict_types=1);

// Envio do código OTP (login e cadastro, telas 10.1/10.2).
//
// UM ponto de envio: send_otp(canal, destino, código). O provedor é escolhido
// por OTP_SENDER. Qual provedor usar (SMS, WhatsApp, e-mail) é decisão do dono
// do produto -- é serviço externo novo, e a cláusula zero exige autorização.
// Até essa decisão, o único "provedor" é:
//
//   log   só em APP_ENV development/testing: não envia nada; o código volta na
//         própria resposta (`dev_code`) pra testar o fluxo. É o padrão nesses
//         ambientes.
//
// Em production/staging não há padrão: sem OTP_SENDER de um provedor real, a
// trava de produção (lib/core/production_guard.php) não deixa a API subir.
// Provedor real entra aqui como mais um `case`, com curl nativo (sem SDK),
// timeout curto e log estruturado SEM o código.

const OTP_DEV_ENVS = ['development', 'testing'];

/** Provedores reais implementados. Vazio até a escolha do provedor. */
const OTP_REAL_SENDERS = [];

function otp_sender_driver(): string
{
    $driver = (string) env('OTP_SENDER', '');
    if ($driver === '' && in_array(app_env(), OTP_DEV_ENVS, true)) {
        return 'log';
    }

    return $driver;
}

/** Por que o envio de OTP não está pronto pra produção (null = pronto). */
function otp_sender_problem(): ?string
{
    $driver = otp_sender_driver();
    if ($driver === '') {
        return 'provedor de OTP não configurado (OTP_SENDER) -- escolha pendente do dono do produto';
    }
    if ($driver === 'log') {
        return 'OTP_SENDER=log só vale em development/testing: o código não seria enviado a ninguém';
    }
    if (!in_array($driver, OTP_REAL_SENDERS, true)) {
        return "OTP_SENDER={$driver} não está implementado";
    }

    return null;
}

/**
 * Envia o código. Devolve false se não enviou (quem chama responde 502 e não
 * conta como enviado). O código nunca vai pro log fora de development/testing.
 */
function send_otp(string $channel, string $destination, string $code): bool
{
    $driver = otp_sender_driver();

    if ($driver === 'log' && in_array(app_env(), OTP_DEV_ENVS, true)) {
        error_log(sprintf('[%s] otp (log, %s) channel=%s destination=%s', trace_id(), app_env(), $channel, substr($destination, 0, 3) . '…'));

        return true;
    }

    error_log(sprintf('[%s] otp: provedor %s indisponível ou não implementado', trace_id(), $driver === '' ? '(nenhum)' : $driver));

    return false;
}
