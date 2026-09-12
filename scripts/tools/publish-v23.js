/**
 * [DEPRECATED / FAIL-CLOSED] Script legado de publicação pontual v23 desativado.
 *
 * Publicações automáticas em workspaces dinâmicos sem parâmetros e sem confirmação
 * foram permanentemente bloqueadas por governança analytics-as-code.
 *
 * Para sincronização canônica segura do container, utilize:
 *   node scripts/tools/sync-canonical-gtm.js --workspace-id=<id> [--apply]
 */

console.error(
  '[FAIL-CLOSED] O script "publish-v23.js" é legado e foi desativado por governança de segurança. ' +
  'Ele não deve ser executado para evitar publicações acidentais no container live. ' +
  'Utilize "node scripts/tools/sync-canonical-gtm.js --workspace-id=<id> [--apply]".'
);
process.exit(1);
