/**
 * [DEPRECATED / FAIL-CLOSED] Script legado de migração pontual v24 desativado.
 *
 * Mutações cegas e publicações automáticas sem verificação explícita de workspace
 * foram permanentemente bloqueadas por governança analytics-as-code.
 *
 * Para sincronização canônica segura do container, utilize:
 *   node scripts/tools/sync-canonical-gtm.js --workspace-id=<id> [--apply]
 */

console.error(
  '[FAIL-CLOSED] O script "update-triggers-v24.js" é legado e foi desativado por governança de segurança. ' +
  'Ele não deve ser executado para evitar sobrescrita indevida do container v30+. ' +
  'Utilize "node scripts/tools/sync-canonical-gtm.js --workspace-id=<id> [--apply]".'
);
process.exit(1);
