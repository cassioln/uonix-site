const { gtmRequest } = require('./gtm-client.js');

async function publish() {
  const base = '/accounts/6348960683/containers/248910884';
  const wsList = await gtmRequest('GET', `${base}/workspaces`);
  const wsId = wsList.workspace[0].workspaceId;
  console.log('Using workspace:', wsId);

  console.log('1. Criando versão a partir do Workspace...');
  const res = await gtmRequest('POST', `${base}/workspaces/${wsId}:create_version`, {
    name: 'v23 - Google Ads Microconversoes Carrinho e Checkout',
    notes: 'Ajusta acionadores de Iniciar Finalização (domReady no checkout /finalizar-orcamento/) e Adicionar ao Carrinho (clique CSS selector + formSubmit).'
  });

  const versionId = res.containerVersion?.containerVersionId;
  console.log(`Versão criada: ${versionId} (${res.containerVersion?.name})`);

  console.log('2. Publicando versão live...');
  const pubRes = await gtmRequest('POST', `${base}/versions/${versionId}:publish`);
  console.log('Publicação concluída! Sucesso:', pubRes.compilerError ? 'com erro' : 'OK');
}

publish().catch(err => {
  console.error('ERRO na publicação:', err);
  process.exit(1);
});
