<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Carrossel da Home (tela 2.1): os banners no ar pra cidade escolhida.
//
// GET ?city_ibge_code=  {banners: [{id, title, image_key, restaurant_id}]}
//
// No ar = ligado, dentro da janela [starts_at, ends_at) e da cidade (ou de
// todas, city_ibge_code NULL). O link pra loja só sai se a loja estiver
// aprovada: banner não leva o cliente a uma loja que não vende.
// A imagem vem de banners/image.php. Criados na aba Banners do admin.

require_method('GET');

$city = only_digits(input_str($_GET, 'city_ibge_code'));
if (strlen($city) !== 7) {
    error_response(422, 'city_ibge_code_required', 'Informe ?city_ibge_code=.');
}

$stmt = db()->prepare(
    'SELECT b.id, b.title, b.image_key,
            CASE WHEN r.approved_at IS NOT NULL THEN r.id END AS restaurant_id
       FROM promo_banners b
       LEFT JOIN restaurants r ON r.id = b.link_restaurant_id
      WHERE b.active
        AND b.starts_at <= now() AND (b.ends_at IS NULL OR b.ends_at > now())
        AND (b.city_ibge_code IS NULL OR b.city_ibge_code = :city)
      ORDER BY b.position, b.id
      LIMIT ' . PROMO_BANNERS_MAX_LIVE
);
$stmt->execute(['city' => $city]);

json_response(200, ['banners' => $stmt->fetchAll()]);
