<?php
/**
 * Nenhum default de rede social pode usar http://.
 *
 * POR QUE ESTE TESTE EXISTE
 *
 * O default do YouTube ficou em `http://` e chegou a produção, servido em todas as
 * páginas. Instagram, Facebook e LinkedIn já estavam em `https` — foi um caso isolado que
 * ninguém notou porque nada verificava.
 *
 * O revisor do PR #112 propôs a asserção e concordo com o racional: é uma string literal,
 * barata de proteger, e a regressão é silenciosa (não quebra nada, só serve link inseguro).
 *
 * A verificação é GENÉRICA de propósito: vale para qualquer chave `social_*` presente ou
 * futura, não só as quatro de hoje. Uma asserção que só olhasse `social_youtube` não teria
 * pegado o problema original em nenhuma das outras redes.
 *
 * Método: leitura estática do fonte. Não dá para carregar o arquivo (ele chama
 * add_menu_page/add_shortcode do WordPress no topo), então extraímos os defaults por
 * regex. É frágil a uma reescrita grande do array — e por isso o teste FALHA ALTO se não
 * encontrar nenhuma chave, em vez de passar vazio.
 */

$raiz  = dirname( __DIR__, 2 );
$alvo  = $raiz . '/mu-plugins/uonix-admin/40-admin-dados-globais-rfq.php';

if ( ! is_readable( $alvo ) ) {
    fwrite( STDERR, "FALHOU: não consegui ler {$alvo}\n" );
    exit( 1 );
}

$fonte = file_get_contents( $alvo );

/* captura: 'social_xxx' => [ ... 'default' => 'VALOR' ] */
preg_match_all(
    "/'(social_[a-z0-9_]+)'\s*=>\s*\[[^\]]*'default'\s*=>\s*'([^']*)'/",
    $fonte,
    $m,
    PREG_SET_ORDER
);

$falhas   = 0;
$asserts  = 0;

/*
 * Guarda contra teste vazio: se a regex parar de casar (refactor do array), o teste
 * passaria sem verificar nada — o modo de falha mais perigoso. Exigimos as 4 redes
 * conhecidas hoje como piso.
 */
$asserts++;
if ( count( $m ) < 4 ) {
    fwrite(
        STDERR,
        sprintf(
            "FALHOU: encontrei apenas %d default(s) social_*; esperava ao menos 4. A estrutura do array mudou e este teste deixou de verificar o que promete — ajuste a extração.\n",
            count( $m )
        )
    );
    $falhas++;
}

foreach ( $m as $par ) {
    list( , $chave, $valor ) = $par;

    /* campo vazio é permitido: significa "rede não usada" (ex.: social_x) */
    if ( '' === $valor ) {
        continue;
    }

    $asserts++;
    if ( 0 === strpos( $valor, 'http://' ) ) {
        fwrite(
            STDERR,
            sprintf(
                "FALHOU: %s usa http:// no default (%s). Todo link de rede social deve ser https — o de http chegou a ser servido em produção em todas as páginas.\n",
                $chave,
                $valor
            )
        );
        $falhas++;
        continue;
    }

    /* um default preenchido que não seja URL http(s) provavelmente é erro de digitação */
    $asserts++;
    if ( 0 !== strpos( $valor, 'https://' ) ) {
        fwrite(
            STDERR,
            sprintf(
                "FALHOU: %s tem default preenchido que não começa com https:// (%s).\n",
                $chave,
                $valor
            )
        );
        $falhas++;
    }
}

if ( $falhas > 0 ) {
    fwrite( STDERR, sprintf( "FALHOU: %d asserções, %d falhas\n", $asserts, $falhas ) );
    exit( 1 );
}

printf(
    "PASS: %d default(s) de rede social, todos em https (%d asserções)\n",
    count( $m ),
    $asserts
);
