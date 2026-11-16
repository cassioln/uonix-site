<?php
/** Contratos da guarda local, com stubs; sem WordPress ou rede. */
define('ABSPATH', __DIR__);
function get_option($nome, $padrao = false) { return $padrao; }
require __DIR__ . '/../plugins/jornada-guarda.php';
function exigir($valor, $mensagem) { if (!$valor) { throw new RuntimeException($mensagem); } }
function recusa($acao) { try { $acao(); } catch (RuntimeException $erro) { return; } throw new RuntimeException('deveria recusar'); }
$cfg = array('run_id' => 'uonix-jornada-' . str_repeat('a', 32), 'esperado' => array('empresa' => 'Empresa Teste'));
$cfg['email'] = $cfg['run_id'] . '@example.invalid';
exigir(uonix_jornada_validar($cfg, 'http://localhost:8080', 'local'), 'config local válida');
recusa(fn() => uonix_jornada_validar($cfg, 'https://uonix.com.br', 'local'));
recusa(fn() => uonix_jornada_validar($cfg, 'http://localhost.exemplo.test', 'local'));
recusa(fn() => uonix_jornada_validar($cfg, 'http://localhost:8080', 'production'));
recusa(fn() => uonix_jornada_validar(array_merge($cfg, array('run_id' => '../outro')), 'http://localhost:8080', 'local'));
recusa(fn() => uonix_jornada_validar(array_merge($cfg, array('email' => 'real@example.test')), 'http://localhost:8080', 'local'));
exigir(uonix_jornada_pertence($cfg['email'], 'Empresa Teste', $cfg), 'registro próprio');
exigir(!uonix_jornada_pertence('outro@example.invalid', 'Empresa Teste', $cfg), 'não apagar e-mail de outro registro');
exigir(!uonix_jornada_pertence($cfg['email'], 'Outra Empresa', $cfg), 'não apagar empresa divergente');
$lead = array('form_id' => 4, 'response' => json_encode(array('capturalead_email' => $cfg['email'], 'capturalead_empresa' => 'Empresa Teste')));
exigir(uonix_jornada_lead_pertence($lead, $cfg), 'lead próprio no Form 4');
exigir(!uonix_jornada_lead_pertence(array_merge($lead, array('form_id' => 2)), $cfg), 'não apagar outro formulário');
exigir(!uonix_jornada_lead_pertence(array('form_id' => 4, 'response' => json_encode(array('capturalead_email' => 'outro@example.invalid', 'observacao' => $cfg['email']))), $cfg), 'LIKE não comprova propriedade do lead');
$mailer = (object) array('Mailer' => 'smtp', 'Host' => 'mailpit', 'Port' => 1025, 'SMTPAuth' => false, 'SMTPSecure' => '');
exigir(uonix_jornada_smtp_local($mailer), 'Mailpit sem autenticação');
foreach (array('Host' => 'smtp.externo.test', 'Port' => 465, 'SMTPAuth' => true, 'SMTPSecure' => 'tls', 'Mailer' => 'mail') as $campo => $valor) {
    $ruim = clone $mailer;
    $ruim->$campo = $valor;
    exigir(!uonix_jornada_smtp_local($ruim), 'recusar SMTP externo: ' . $campo);
}
echo "OK: guarda local, propriedade dos registros e transporte SMTP\n";

// Preparação parcial: só a pasta vazia ou a cópia exata podem ser removidas.
$pasta = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/uonix-guarda-' . bin2hex(random_bytes(8));
$conteudo = '<?php // guarda sintética';
exigir(uonix_jornada_remover_guarda($pasta, $conteudo), 'pasta ainda não criada');
mkdir($pasta, 0700);
exigir(uonix_jornada_remover_guarda($pasta, $conteudo) && !file_exists($pasta), 'pasta vazia de preparação parcial');
mkdir($pasta, 0700);
file_put_contents($pasta . '/guarda.php', $conteudo);
exigir(uonix_jornada_remover_guarda($pasta, $conteudo) && !file_exists($pasta), 'cópia exata da guarda');
mkdir($pasta, 0700);
try {
    file_put_contents($pasta . '/guarda.php', 'conteúdo de outro processo');
    recusa(fn() => uonix_jornada_remover_guarda($pasta, $conteudo));
    exigir(is_file($pasta . '/guarda.php'), 'não remover conteúdo divergente');
    unlink($pasta . '/guarda.php');
    file_put_contents($pasta . '/outro.txt', 'alheio');
    recusa(fn() => uonix_jornada_remover_guarda($pasta, $conteudo));
    exigir(is_file($pasta . '/outro.txt'), 'não remover arquivos alheios');
} finally {
    foreach (array('guarda.php', 'outro.txt') as $nome) { if (is_file($pasta . '/' . $nome)) { unlink($pasta . '/' . $nome); } }
    rmdir($pasta);
}
echo "OK: limpeza de preparação parcial e recusa de conteúdo divergente\n";
