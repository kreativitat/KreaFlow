#!/usr/bin/env python3
"""Create only the task-specific, disposable synthetic native-test environment."""
from pathlib import Path
import argparse
import json
import subprocess
import tempfile
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--dolibarr-root', type=Path, required=True, help='Dolibarr 24.0.2 html source directory')
parser.add_argument('--php-image', required=True, help='Existing PHP image with Dolibarr mysqli/runtime extensions')
args = parser.parse_args()
ROOT = Path(__file__).resolve().parents[1]
HTML = args.dolibarr_root.resolve()
if not (HTML / 'master.inc.php').is_file():
    raise SystemExit('Dolibarr source root is invalid')
private = tempfile.TemporaryDirectory(prefix='kreaflow-invoicesync-')
HERE = Path(private.name)

NETWORK = 'kf-invoicesync-test'
DB = 'kf-invoicesync-test-db'
PHP = 'kf-invoicesync-test-php'


def run(args, **kwargs):
    return subprocess.run(args, capture_output=True, text=True, check=True, **kwargs)


for kind, name in [('network', NETWORK), ('container', DB), ('container', PHP)]:
    if subprocess.run(['docker', kind, 'inspect', name], capture_output=True).returncode == 0:
        raise SystemExit('Refusing to overwrite an existing task resource: ' + name)

created_containers = []
created_network = False
try:
    config = {
        'dolibarr_main_url_root': 'http://kf-invoicesync-test.invalid',
        'dolibarr_main_document_root': '/var/www/html',
        'dolibarr_main_url_root_alt': '/custom',
        'dolibarr_main_document_root_alt': '/var/www/html/custom',
        'dolibarr_main_data_root': '/tmp/kf-credit-docs',
        'dolibarr_main_db_host': DB,
        'dolibarr_main_db_port': '3306',
        'dolibarr_main_db_name': 'kreaflow_invoicesync_test',
        'dolibarr_main_db_user': 'root',
        'dolibarr_main_db_pass': '',
        'dolibarr_main_db_type': 'mysqli',
        'dolibarr_main_db_prefix': 'llx_',
        'dolibarr_main_db_character_set': 'utf8mb4',
        'dolibarr_main_db_collation': 'utf8mb4_unicode_ci',
        'dolibarr_main_db_cryptkey': '',
        'dolibarr_main_authentication': 'dolibarr',
        'dolibarr_main_instance_unique_id': 'kf-invoicesync-test-synthetic-only',
        'dolibarr_main_prod': '0',
    }
    text = '<?php\n' + ''.join('$' + key + '=' + json.dumps(value) + ';\n' for key, value in config.items())
    text += '$dolibarr_main_db_encryption=0;\n$dolibarr_main_limit_users=0;\n'
    (HERE / 'conf.php').write_text(text)
    (HERE / 'conf.php').chmod(0o600)
    run(['docker', 'network', 'create', '--internal', NETWORK])
    created_network = True
    run(['docker', 'run', '-d', '--name', DB, '--network', NETWORK,
         '--tmpfs', '/var/lib/mysql:rw,size=1073741824',
         '-e', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1',
         '-e', 'MARIADB_DATABASE=kreaflow_invoicesync_test',
         'mariadb:11.4', '--innodb-buffer-pool-size=64M'])
    created_containers.append(DB)
    for attempt in range(45):
        ready = subprocess.run(['docker', 'exec', DB, 'mariadb', '-uroot', '-N',
                                'kreaflow_invoicesync_test', '-e', 'SELECT 1'], capture_output=True)
        if ready.returncode == 0:
            break
        time.sleep(1)
    else:
        raise SystemExit('Disposable MariaDB did not become ready; existing infrastructure was untouched')


    def installer_sql(path):
        # Dolibarr's installer strips comment-only lines and expands this entity token.
        return '\n'.join(line for line in path.read_text().splitlines()
                         if not line.lstrip().startswith('--')).replace('__ENTITY__', '1')


    tables = HTML / 'install/mysql/tables'
    files = (sorted(path for path in tables.glob('*.sql') if '.key.' not in path.name)
             + sorted(tables.glob('*.key.sql'))
             + sorted((HTML / 'install/mysql/data').glob('llx_c_*.sql')))
    schema = 'SET FOREIGN_KEY_CHECKS=0;\n' + '\n'.join(installer_sql(path) for path in files)
    schema += '\nSET FOREIGN_KEY_CHECKS=1;\n'
    result = subprocess.run(['docker', 'exec', '-i', DB, 'mariadb', '--force', '-uroot',
                             'kreaflow_invoicesync_test'], input=schema, capture_output=True, text=True)
    (HERE / 'schema-output.txt').write_text(result.stdout + result.stderr)
    errors = [line for line in result.stderr.splitlines() if line.startswith('ERROR')]
    # This exact duplicate is explicitly documented as ignorable in the upstream file.
    unexpected = [line for line in errors if "Duplicate key name 'uk_code_region'" not in line]
    if unexpected:
        raise SystemExit('Synthetic schema failure: ' + '; '.join(unexpected[:5]))

    constants = {
        'MAIN_MODULE_FACTURE': '1', 'MAIN_MODULE_SOCIETE': '1', 'MAIN_MODULE_USER': '1',
        'MAIN_MONNAIE': 'EUR', 'MAIN_INFO_SOCIETE_NOM': 'Synthetic Test Company',
        'MAIN_INFO_SOCIETE_COUNTRY': '14:PT:Portugal', 'MAIN_INFO_SOCIETE_TVAOPTION': '1',
        'MAIN_VERSION_LAST_INSTALL': '24.0.2', 'MAIN_DISABLE_ALL_MAILS': '1',
        'FACTURE_ADDON': 'terre', 'FACTURE_ADDON_PDF': 'crabe', 'ZS_IVA_INCLUIDO': '1',
        'ZS_API_STORE': '18', 'MAIN_DISABLE_PDF_AUTOUPDATE': '1', 'MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND': '0',
    }
    seed = """
    INSERT INTO llx_user(rowid,entity,login,admin,statut,lastname)
    VALUES(1,0,'credit-test-admin',1,1,'Synthetic');
    INSERT INTO llx_societe(rowid,entity,nom,client,status,code_client,fk_pays,tva_assuj)
    VALUES(1,1,'Synthetic Credit Customer',1,1,'CREDITTEST',14,1);
    INSERT INTO llx_rights_def(id,entity,module,perms,libelle)
    VALUES(12,1,'facture','creer','Synthetic invoice creation');
    INSERT INTO llx_user_rights(entity,fk_user,fk_id) VALUES(1,1,12);
    """
    seed += '\n'.join("INSERT INTO llx_const(name,value,type,entity) VALUES('%s','%s','chaine',1);"
                      % (key, value) for key, value in constants.items())
    run(['docker', 'exec', '-i', DB, 'mariadb', '-uroot', 'kreaflow_invoicesync_test'], input=seed)
    run(['docker', 'run', '-d', '--name', PHP, '--network', NETWORK, '--entrypoint', 'tail',
         '-v', str(HTML) + ':/var/www/html:ro',
         '-v', str(HERE / 'conf.php') + ':/var/www/html/conf/conf.php:ro',
         '-v', str(ROOT) + ':/var/www/html/custom/kreaflow:ro',
         args.php_image, '-f', '/dev/null'])
    created_containers.append(PHP)
    run(['docker', 'exec', PHP, 'mkdir', '-p', '/tmp/kf-credit-docs/facture', '/tmp/kf-credit-docs/societe'])
    for name in ['invoice_sync_retry_dolibarr_test.php', 'invoice_source_identity_dolibarr_test.php', 'invoice_credit_note_dolibarr_test.php',
                 'invoice_rounding_recovery_dolibarr_test.php']:
        result = subprocess.run(['docker', 'exec', PHP, 'php',
                                 '/var/www/html/custom/kreaflow/test/' + name], capture_output=True, text=True)
        print(result.stdout, end='')
        if result.returncode:
            raise RuntimeError(result.stderr or 'Integration suite failed: ' + name)
finally:
    for name in reversed(created_containers):
        subprocess.run(['docker', 'rm', '-f', name], capture_output=True)
    if created_network:
        subprocess.run(['docker', 'network', 'rm', NETWORK], capture_output=True)
    private.cleanup()
