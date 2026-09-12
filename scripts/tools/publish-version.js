const { gtmRequest } = require('./gtm-client.js');

async function publish() {
  const base = '/accounts/6348960683/containers/248910884';
  const ws = '23';

  console.log('1. Criando versão a partir do Workspace...');
  const res = await gtmRequest('POST', `${base}/workspaces/${ws}/create_version`, {
    name: 'v22 - Google Ads Microconversoes e Contatos Diretos',
    notes: 'Adiciona tags secundárias: Adicionar ao Carrinho, Iniciar Finalização, Contato Telefone e Contato Email com respeito estrito a LGPD/AdOpt.'
  });

  const versionId = res.containerVersion?.containerVersionId;
  console.log(`Versão criada: ${versionId} (${res.containerVersion?.name})`);

  console.log('2. Publicando versão live...');
  const pubRes = await gtmRequest('POST', `${base}/versions/${versionId}:publish`);
  console.log('Publicação concluída! Sucesso:', pubRes);
}

publish().catch(err => {
  console.error('ERRO na publicação:', err);
  process.exit(1);
});
