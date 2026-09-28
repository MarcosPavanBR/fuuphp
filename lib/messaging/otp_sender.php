<?php
declare(strict_types=1);

// Envio do código OTP (login e cadastro, telas 10.1/10.2).
//
// UM ponto de envio: send_otp(canal, destino, código). O provedor é escolhido
// por OTP_SENDER:
//
//   log     só em APP_ENV development/testing: não envia nada; o código volta
//           na própria resposta (`dev_code`) pra testar o fluxo. É o padrão
//           nesses ambientes. Aceita todos os canais.
//   twilio  SMS pela API REST da Twilio (decisão do Marcos no go-live), com
//           curl nativo -- sem SDK, sem Composer. WhatsApp pela mesma API só
//           se houver remetente aprovado (TWILIO_WHATSAPP_FROM). E-mail a
//           Twilio não envia por esta API: com twilio, login por e-mail fica
//           indisponível e o app esconde a opção (auth/channels.php).
//
// Em production/staging não há padrão: sem OTP_SENDER de um provedor real,
// ou com credencial faltando, a trava de produção
// (lib/core/production_guard.php) não deixa a API subir.
//
// O código NUNCA vai pro log. Falha de envio registra só o status e o código
// de erro do provedor, com o destino mascarado.

const OTP_DEV_ENVS = ['development', 'testing'];

/** Provedores reais implementados. */
const OTP_REAL_SENDERS = ['twilio'];

const TWILIO_DEFAULT_API_BASE = 'https://api.twilio.com';
const TWILIO_CONNECT_TIMEOUT_SECONDS = 3;
const TWILIO_TIMEOUT_SECONDS = 6;

/**
 * O provedor em uso (OTP_SENDER); vazio em development/testing vira `log`.
 */
function otp_sender_driver(): string
{
    $driver = (string) env('OTP_SENDER', '');
    if ($driver === '' && in_array(app_env(), OTP_DEV_ENVS, true)) {
        return 'log';
    }

    return $driver;
}

/**
 * Canais que o provedor configurado entrega de verdade. É o que a tela de
 * login oferece (auth/channels.php) e o que otp_request aceita.
 *
 * @return list<string>
 */
function otp_sender_channels(): array
{
    return match (otp_sender_driver()) {
        'log' => in_array(app_env(), OTP_DEV_ENVS, true) ? ['sms', 'whatsapp', 'email'] : [],
        'twilio' => (string) env('TWILIO_WHATSAPP_FROM', '') !== '' ? ['sms', 'whatsapp'] : ['sms'],
        default => [],
    };
}

/** Por que o envio de OTP não está pronto pra produção (null = pronto). */
function otp_sender_problem(): ?string
{
    $driver = otp_sender_driver();
    if ($driver === '') {
        return 'provedor de OTP não configurado (OTP_SENDER)';
    }
    if ($driver === 'log') {
        return 'OTP_SENDER=log só vale em development/testing: o código não seria enviado a ninguém';
    }
    if (!in_array($driver, OTP_REAL_SENDERS, true)) {
        return "OTP_SENDER={$driver} não está implementado";
    }

    if ($driver === 'twilio') {
        if (!preg_match('/^AC[0-9a-f]{32}$/i', (string) env('TWILIO_ACCOUNT_SID', ''))) {
            return 'TWILIO_ACCOUNT_SID vazio ou inválido (começa com AC e tem 34 caracteres)';
        }
        if ((string) env('TWILIO_AUTH_TOKEN', '') === '') {
            return 'TWILIO_AUTH_TOKEN vazio';
        }
        $from = (string) env('TWILIO_FROM', '');
        $service = (string) env('TWILIO_MESSAGING_SERVICE_SID', '');
        if ($service === '' && !preg_match('/^\+[1-9][0-9]{7,14}$/', $from)) {
            return 'defina TWILIO_MESSAGING_SERVICE_SID (MG...) ou TWILIO_FROM no formato +5511999999999';
        }
        // A base só muda nos testes (servidor falso local). Em produção,
        // apontar pra outro lugar seria mandar a credencial pra outro lugar.
        if (twilio_api_base() !== TWILIO_DEFAULT_API_BASE) {
            return 'TWILIO_API_BASE só pode ser alterada em development/testing';
        }
    }

    return null;
}

/**
 * Envia o código. Devolve false se não enviou (quem chama responde 502 e não
 * conta como enviado). O código nunca vai pro log.
 */
function send_otp(string $channel, string $destination, string $code): bool
{
    $driver = otp_sender_driver();

    if (!in_array($channel, otp_sender_channels(), true)) {
        error_log(sprintf('[%s] otp: canal %s indisponível no provedor %s', trace_id(), $channel, $driver === '' ? '(nenhum)' : $driver));

        return false;
    }

    if ($driver === 'log') {
        error_log(sprintf('[%s] otp (log, %s) channel=%s destination=%s', trace_id(), app_env(), $channel, otp_mask($destination)));

        return true;
    }

    if ($driver === 'twilio') {
        return twilio_send_otp($channel, $destination, $code);
    }

    error_log(sprintf('[%s] otp: provedor %s não implementado', trace_id(), $driver === '' ? '(nenhum)' : $driver));

    return false;
}

/** Texto da mensagem: curto (1 segmento de SMS), sem link, com o prazo. */
function otp_message(string $code): string
{
    return sprintf(
        'FUU: seu código é %s. Vale por %d minutos. Não passe pra ninguém.',
        $code,
        intdiv(OTP_TTL_SECONDS, 60)
    );
}

/** Destino mascarado pro log: "+55119…25". */
function otp_mask(string $destination): string
{
    return mb_strlen($destination) <= 6 ? '…' : mb_substr($destination, 0, 6) . '…' . mb_substr($destination, -2);
}

/**
 * Telefone guardado só com dígitos -> E.164 (+55...). Número de 10 ou 11
 * dígitos é brasileiro sem o 55 (DDD + número); 12 ou 13 começando por 55 já
 * tem o país.
 */
function phone_to_e164(string $phone): ?string
{
    $digits = only_digits($phone);
    if (strlen($digits) === 10 || strlen($digits) === 11) {
        return '+55' . $digits;
    }
    if ((strlen($digits) === 12 || strlen($digits) === 13) && str_starts_with($digits, '55')) {
        return '+' . $digits;
    }

    return null;
}

/**
 * Base da API da Twilio. Só muda nos testes (Twilio falsa); a trava de
 * produção recusa qualquer outra.
 */
function twilio_api_base(): string
{
    return rtrim((string) env('TWILIO_API_BASE', TWILIO_DEFAULT_API_BASE), '/');
}

/**
 * POST /2010-04-01/Accounts/{SID}/Messages.json (Basic auth SID:token).
 * 201 = aceito pela Twilio. Qualquer outra coisa -- erro HTTP, timeout,
 * resposta estranha -- é "não enviado".
 */
function twilio_send_otp(string $channel, string $destination, string $code): bool
{
    $to = phone_to_e164($destination);
    if ($to === null) {
        error_log(sprintf('[%s] otp twilio: telefone fora do formato brasileiro (%s)', trace_id(), otp_mask($destination)));

        return false;
    }

    $sid = (string) env('TWILIO_ACCOUNT_SID', '');
    $fields = ['To' => $to, 'Body' => otp_message($code)];
    if ($channel === 'whatsapp') {
        $fields['To'] = 'whatsapp:' . $to;
        $fields['From'] = 'whatsapp:' . (string) env('TWILIO_WHATSAPP_FROM', '');
    } elseif ((string) env('TWILIO_MESSAGING_SERVICE_SID', '') !== '') {
        $fields['MessagingServiceSid'] = (string) env('TWILIO_MESSAGING_SERVICE_SID', '');
    } else {
        $fields['From'] = (string) env('TWILIO_FROM', '');
    }

    $ch = curl_init(twilio_api_base() . '/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_USERPWD => $sid . ':' . (string) env('TWILIO_AUTH_TOKEN', ''),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => TWILIO_CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => TWILIO_TIMEOUT_SECONDS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_errno($ch) !== 0 ? curl_error($ch) : null;
    curl_close($ch);

    if ($curlError !== null) {
        error_log(sprintf('[%s] otp twilio: falha de rede (%s) para %s', trace_id(), $curlError, otp_mask($to)));

        return false;
    }
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if ($status !== 201 || !is_array($json) || !isset($json['sid'])) {
        // Só o código de erro da Twilio (ex.: 21211 número inválido, 21608
        // conta trial) -- nunca o corpo inteiro, que ecoa a mensagem com o código.
        error_log(sprintf(
            '[%s] otp twilio: HTTP %d, erro %s, para %s',
            trace_id(),
            $status,
            is_array($json) && isset($json['code']) ? (string) $json['code'] : '?',
            otp_mask($to)
        ));

        return false;
    }

    return true;
}
