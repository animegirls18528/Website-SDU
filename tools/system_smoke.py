"""Integration smoke tests in an isolated app copy and newly created QA database.

Run: python tools/system_smoke.py --php C:/xampp/php/php.exe
Requires installed Composer dependencies and local MySQL credentials in DB_* env.
Never scans hardware, uses production sessions, or deletes production data.
Artifacts and generated credentials remain in ignored .qa-runtime/.
"""
import argparse
import base64
import hashlib
import hmac
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request


ROOT = Path(__file__).resolve().parents[1]
RESULTS = []


class Client:
    def __init__(self, base):
        self.base = base
        self.token = ''
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(self, path, data=None, headers=None, raw=None):
        h = {'X-CSRF-Token': self.token}
        h.update(headers or {})
        if data is not None:
            raw = json.dumps(data).encode()
            h['Content-Type'] = 'application/json'
        req = urllib.request.Request(self.base + path, data=raw, headers=h)
        try:
            response = self.opener.open(req, timeout=35)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read()
        try:
            result = json.loads(body)
        except (ValueError, UnicodeDecodeError):
            result = body
        return response.code, result

    def login(self, username, password):
        status, data = self.request('/api/auth.php', {'username': username, 'password': password})
        assert status == 200 and data.get('success'), 'Login failed'
        status, html = self.request('/index.php')
        match = re.search(rb'window.CSRF_TOKEN\s*=\s*"([^"]+)"', html)
        assert match, 'Missing CSRF token'
        self.token = match[1].decode()

    def upload(self, path, content, filename='qa.pdf', fields=None, headers=None):
        boundary = 'edmsqa' + secrets.token_hex(8)
        body = bytearray()
        for key, value in (fields or {}).items():
            body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\nContent-Type: application/pdf\r\n\r\n'.encode())
        body.extend(content)
        body.extend(f'\r\n--{boundary}--\r\n'.encode())
        return self.request(path, raw=bytes(body), headers={'Content-Type': f'multipart/form-data; boundary={boundary}', **(headers or {})})


def check(name, fn):
    try:
        fn()
        RESULTS.append({'test': name, 'passed': True})
        print('PASS ' + name, flush=True)
    except Exception as error:
        RESULTS.append({'test': name, 'passed': False, 'error': str(error)[:350]})
        print('FAIL ' + name + ': ' + str(error)[:200], flush=True)


def expect(client, path, data=None, code=200, key=None):
    status, response = client.request(path, data)
    assert status == code, f'Expected HTTP {code}, got {status}: {str(response)[:160]}'
    if code == 200 and isinstance(response, dict):
        assert response.get('success', True), str(response)[:200]
    if code == 200 and isinstance(response, bytes):
        assert not re.search(rb'(Fatal error:|<b>Warning</b>|Uncaught .*Exception)', response), response[:200]
    if key:
        assert key in response, f'Missing {key}: {str(response)[:160]}'
    return response


def main():
    args = argparse.ArgumentParser()
    args.add_argument('--php', default='php')
    opts = args.parse_args()
    run = ROOT / '.qa-runtime' / ('run_' + time.strftime('%Y%m%d_%H%M%S') + '_' + secrets.token_hex(3))
    app = run / 'app'
    app.mkdir(parents=True)
    for directory in ['api', 'assets', 'config', 'tools']:
        shutil.copytree(ROOT / directory, app / directory, ignore=shutil.ignore_patterns('scanner-runtime', '__pycache__', 'local.php'))
    shutil.copytree(ROOT / 'vendor', app / 'vendor')
    for source in ROOT.glob('*.php'):
        shutil.copy2(source, app / source.name)
    (app / 'uploads').mkdir()
    # All configurable filesystem destinations stay inside this run's sandbox.
    (app / 'config' / 'local.php').write_text("<?php\ndefine('BACKUP_DIR', __DIR__ . '/../../backups');\ndefine('SIGN_KEY_DIR', __DIR__ . '/../../keys');\n", encoding='utf-8')
    env = os.environ.copy()
    env['DB_NAME'] = 'edms_qa_' + secrets.token_hex(5)
    env['QA_PASSWORD'] = secrets.token_urlsafe(24)
    bootstrap = run / 'bootstrap.php'
    bootstrap.write_text('''<?php
require $argv[1] . '/vendor/autoload.php';
$db = getenv('DB_NAME');
if (!preg_match('/^edms_qa_[a-f0-9]+$/', $db)) exit(2);
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306'), getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
require $argv[1] . '/config/database.php';
$ins=$pdo->prepare('INSERT INTO users (Username, Password, Role) VALUES (?, ?, ?)');
foreach (['admin','staff','viewer'] as $role) $ins->execute(['qa_' . $role,password_hash(getenv('QA_PASSWORD'),PASSWORD_DEFAULT),$role]);
// Emulate a v5 legacy database without folder ordering, then verify the upgrade.
$pdo->exec('ALTER TABLE folders DROP COLUMN SortOrder');
$pdo->exec("UPDATE system_settings SET SettingValue='5' WHERE SettingKey='edms_schema_version'");
edmsMigrate($pdo);
if (!$pdo->query("SHOW COLUMNS FROM folders LIKE 'SortOrder'")->fetch()) exit(3);
$pdf=new FPDF(); $pdf->AddPage(); $pdf->SetFont('Arial','',12); $pdf->Cell(50,10,'EDMS QA document'); $pdf->Output('F',$argv[2]);
echo 'QA bootstrap and legacy schema upgrade OK';
''', encoding='utf-8')
    subprocess.run([opts.php, str(bootstrap), str(app), str(run / 'qa.pdf')], env=env, check=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    log = (run / 'server.log').open('w', encoding='utf-8')
    server = subprocess.Popen([opts.php, '-S', f'127.0.0.1:{port}', '-t', str(app)], env=env, stdout=log, stderr=log)
    base = f'http://127.0.0.1:{port}'
    admin, staff, viewer, anon = [Client(base) for _ in range(4)]
    try:
        for _ in range(40):
            try:
                anon.request('/login.php')
                break
            except urllib.error.URLError:
                time.sleep(.1)
        for role, client in [('admin', admin), ('staff', staff), ('viewer', viewer)]:
            check('login_' + role, lambda c=client, r=role: c.login('qa_' + r, env['QA_PASSWORD']))
        check('unauthenticated_api_denied', lambda: expect(anon, '/api/init.php', code=401))
        check('csrf_required', lambda: expect(anon, '/api/agent.php', {'action': 'start'}, code=401))
        saved_token = admin.token
        admin.token = ''
        check('authenticated_missing_csrf_denied', lambda: expect(admin, '/api/settings.php', {'action': 'addFolder', 'FolderName': 'Blocked'}, code=403))
        admin.token = saved_token
        reads = [('init', None), ('dashboard_stats', None), ('search', {}), ('notifications', None),
                 ('settings', {'action':'getSystemSettings'}), ('settings', {'action':'getPolicy'}),
                 ('settings', {'action':'storageReport'}), ('settings', {'action':'listBackups'}),
                 ('profiles', {'action':'list'}), ('saved', {'action':'list'}), ('locations', {'action':'list'}),
                 ('locations', {'action':'loans'}), ('links', {'action':'types'}), ('share', {'action':'list'}),
                 ('destruction', {'action':'list'}), ('security', {'action':'status'}), ('security', {'action':'sessions'}),
                 ('security', {'action':'tokens'}), ('inbox', {'action':'list'}), ('integrity', {'action':'status'}),
                 ('scan', {'action':'getScanSettings'}), ('scan', {'action':'resume'}), ('users', {'action':'list'})]
        for endpoint, data in reads:
            name = endpoint + '_' + (data or {}).get('action', 'read')
            check(name, lambda e=endpoint,d=data: expect(admin, '/api/' + e + '.php', d))
        for report in ['fiscal','productivity','disk','pii']:
            check('report_' + report, lambda r=report: expect(admin, '/api/reports.php', {'report':r}))
        for endpoint, action in [('settings','getPolicy'),('users','list'),('share','list'),('destruction','list'),('reports',None),('dashboard_stats',None)]:
            check('viewer_denied_' + endpoint, lambda e=endpoint,a=action: expect(viewer, '/api/' + e + '.php', {'action':a} if a else None, code=403))
        check('viewer_cannot_create_document', lambda: expect(viewer, '/api/document.php', {'action':'createDocument','ID':'QA_DENIED','Filename':'Denied','FolderId':1},code=403))
        check('viewer_search_allowed', lambda: expect(viewer, '/api/search.php', {}))
        check('scanner_get_cannot_trigger_hardware', lambda: expect(admin, '/api/scan.php?action=start', code=405))
        check('scanner_get_cannot_cancel_jobs', lambda: expect(admin, '/api/scan.php?action=cancel', code=405))
        check('folder_create', lambda: expect(admin, '/api/settings.php', {'action':'addFolder','FolderName':'QA test folder'}))
        check('prefix_create', lambda: expect(admin, '/api/settings.php', {'action':'addPrefix','PrefixValue':'QA','PrefixName':'QA test','RetentionYears':1}))
        check('custom_field_create', lambda: expect(admin, '/api/settings.php', {'action':'addField','FieldName':'QA amount','FieldType':'number','IsRequired':'No'}))
        check('profile_save', lambda: expect(admin, '/api/profiles.php', {'action':'save','Name':'QA profile','Options':{'dpi':300,'source':'feeder'},'Prefix':'QA','FolderId':1}))
        check('saved_search_save', lambda: expect(admin, '/api/saved.php', {'action':'save','Name':'QA saved','Filters':{'q':'QA'}}))
        check('document_create_metadata', lambda: expect(staff, '/api/document.php', {'action':'createDocument','ID':'QA000001','Filename':'QA document one','FolderId':1,'PaymentDate':'2026-01-01','WorkflowStatus':'Draft','Tags':'qa'}))
        check('document_create_duplicate_denied', lambda: expect(admin, '/api/document.php', {'action':'createDocument','ID':'QA000001','Filename':'Duplicate','FolderId':1},code=409))
        check('document_get', lambda: expect(admin, '/api/document.php', {'action':'get','ID':'QA000001'},key='document'))
        check('document_submit', lambda: expect(staff, '/api/document.php', {'action':'setWorkflow','ID':'QA000001','WorkflowStatus':'PendingReview'}))
        check('staff_cannot_approve', lambda: expect(staff, '/api/document.php', {'action':'setWorkflow','ID':'QA000001','WorkflowStatus':'Approved'},code=403))
        check('document_approve', lambda: expect(admin, '/api/document.php', {'action':'setWorkflow','ID':'QA000001','WorkflowStatus':'Approved'}))
        def edit():
            doc=expect(admin,'/api/document.php',{'action':'get','ID':'QA000001'})['document']
            expect(admin,'/api/document.php',{'action':'updateDocumentMetadata','oldId':'QA000001','RowVersion':doc['RowVersion'],'Filename':'QA updated title'})
        check('document_edit',edit)
        check('stale_edit_denied', lambda: expect(admin,'/api/document.php',{'action':'updateDocumentMetadata','oldId':'QA000001','RowVersion':0,'Filename':'Stale'},code=409))
        def upload_pdf():
            status,data=admin.upload('/api/upload.php',(run/'qa.pdf').read_bytes())
            assert status==200 and data.get('success'),str(data)
            result=expect(admin,'/api/document.php',{'action':'createDocument','ID':'QA000002','Filename':'QA PDF attachment','FolderId':1,'newFileId':[data['fileId']]})
            assert not result.get('stampFailed'), 'QR stamping failed'
            doc=expect(admin,'/api/document.php',{'action':'get','ID':'QA000002'})['document']
            assert doc['Files'] and doc['Files'][0]['PageCount']==1,str(doc['Files'])
        check('pdf_upload_attach_qr_and_page_count',upload_pdf)
        def pdf_download():
            doc=expect(admin,'/api/document.php',{'action':'get','ID':'QA000002'})['document']
            stored=doc['Files'][0]['StoredPath']
            for suffix in ['', '&download=1']:
                result=expect(admin,'/api/file.php?f='+urllib.parse.quote(stored)+suffix)
                assert result.startswith(b'%PDF'), 'Not a PDF response'
            public=expect(anon,'/verify.php?t='+doc['VerifyToken'])
            assert b'QA000002' in public,'Verification page missing document ID'
        check('file_preview_watermarked_download_and_public_verify',pdf_download)
        def replace_file():
            doc=expect(admin,'/api/document.php',{'action':'get','ID':'QA000002'})['document']
            status,data=admin.upload('/api/upload.php',(run/'qa.pdf').read_bytes())
            assert status==200 and data['success']
            result=expect(admin,'/api/document.php',{'action':'replaceFile','ID':'QA000002','RowVersion':doc['RowVersion'],'FileRowId':doc['Files'][0]['ID'],'newFileId':[data['fileId']]})
            assert result['updatedDoc']['Files'][0]['Version']==2,'File version did not advance'
            restored=expect(admin,'/api/document.php',{'action':'get','ID':'QA000002'})['document']
            assert len(restored['Versions'])==1,'Old version missing'
        check('file_replace_preserves_version_history',replace_file)
        def pii_policy():
            result=expect(admin,'/api/document.php',{'action':'setPii','ID':'QA000002','flag':True,'Summary':'QA sensitive'})
            stored=result['updatedDoc']['Files'][0]['StoredPath']
            expect(viewer,'/api/file.php?f='+urllib.parse.quote(stored),code=403)
            expect(admin,'/api/document.php',{'action':'setPii','ID':'QA000002','flag':False})
        check('pii_file_access_denied_for_viewer',pii_policy)
        def reject_upload():
            status,_=admin.upload('/api/upload.php',b'<?php echo 1;',filename='malicious.php')
            assert status==400,status
        check('upload_disallowed_file_rejected',reject_upload)
        def search():
            r=expect(admin,'/api/search.php',{'q':'QA updated title'})
            assert any(d['ID']=='QA000001' for d in r['documents']),str(r)
        check('search_finds_updated_document',search)
        check('location_create', lambda: expect(admin,'/api/locations.php',{'action':'save','Room':'QA Room','Cabinet':'A'}))
        check('location_assign', lambda: expect(staff,'/api/locations.php',{'action':'setDocLocation','DocumentID':'QA000001','LocationId':1}))
        check('loan_borrow', lambda: expect(staff,'/api/locations.php',{'action':'borrow','DocumentID':'QA000001','BorrowerName':'QA Borrower','DueDate':'2099-01-01'}))
        check('loan_duplicate_denied', lambda: expect(staff,'/api/locations.php',{'action':'borrow','DocumentID':'QA000001','BorrowerName':'QA Borrower','DueDate':'2099-01-01'},code=409))
        check('loan_return', lambda: expect(staff,'/api/locations.php',{'action':'return','LoanID':1}))
        check('cover_batch_create',lambda: expect(staff,'/api/cover.php',{'action':'create','Prefix':'QA','FolderId':1,'Titles':['QA Cover A','QA Cover B']}))
        check('cover_resolve',lambda: expect(staff,'/api/cover.php',{'action':'resolve','IDs':['QA000001']}))
        check('cover_mark_printed',lambda: expect(staff,'/api/cover.php',{'action':'markPrinted','IDs':['QA000001']}))
        def cover_print():
            html=expect(admin,'/cover_print.php?ids=QA000001')
            assert b'data:image/svg+xml;base64,' in html,'Missing QR image'
        check('cover_print_renders_qr_without_gd',cover_print)
        check('related_document_add',lambda: expect(staff,'/api/links.php',{'action':'add','DocA':'QA000001','DocB':'QA000002','RelationType':'เกี่ยวข้อง'}))
        check('related_document_list',lambda: expect(staff,'/api/links.php',{'action':'list','DocumentID':'QA000001'}))
        check('bulk_tag',lambda: expect(staff,'/api/bulk.php',{'op':'addTags','IDs':['QA000001','QA000002'],'Tags':'batchqa'}))
        def share():
            result=expect(admin,'/api/share.php',{'action':'create','Recipient':'QA Reviewer','ExpiresAt':time.strftime('%Y-%m-%d'),'IDs':['QA000001']})
            path=urllib.parse.urlsplit(result['url'])
            expect(anon,path.path+'?'+path.query)
            expect(admin,'/api/share.php',{'action':'revoke','ID':result['id']})
        check('reviewer_share_create_open_revoke',share)
        def agent():
            result=expect(admin,'/api/security.php',{'action':'createToken','Name':'QA Agent'})
            token=result['token']
            headers={'Authorization':'Bearer '+token}
            status,data=anon.request('/api/agent.php?ping=1',headers=headers)
            assert status==200 and data['success'],str(data)
            status,data=anon.upload('/api/agent.php',(run/'qa.pdf').read_bytes(),headers=headers)
            assert status==200 and data['success'],str(data)
            expect(admin,'/api/inbox.php',{'action':'take','ID':data['inboxId']})
        check('scan_agent_token_upload_inbox_take',agent)
        check('csv_export',lambda: expect(admin,'/api/export.php'))
        check('document_trash',lambda: expect(admin,'/api/document.php',{'action':'deleteDocument','ID':'QA000001','Reason':'QA'}))
        check('document_restore',lambda: expect(admin,'/api/document.php',{'action':'restoreDocument','ID':'QA000001'}))
        check('destruction_unexpired_denied',lambda: expect(staff,'/api/document.php',{'action':'requestDestruction','ID':'QA000001'},code=400))
        check('expired_document_create',lambda: expect(admin,'/api/document.php',{'action':'createDocument','ID':'QA_EXPIRED','Filename':'QA expired','FolderId':1,'PaymentDate':'2000-01-01'}))
        check('destruction_request',lambda: expect(staff,'/api/document.php',{'action':'requestDestruction','ID':'QA_EXPIRED'}))
        check('destruction_batch_create',lambda: expect(admin,'/api/destruction.php',{'action':'create','source':'requested','Title':'QA destruction'}))
        check('destruction_requires_committee',lambda: expect(admin,'/api/destruction.php',{'action':'setStatus','ID':1,'Status':'review'},code=400))
        check('destruction_committee',lambda: expect(admin,'/api/destruction.php',{'action':'saveCommittee','ID':1,'Committee':[{'name':'QA member '+str(n)} for n in range(3)]}))
        check('destruction_submit_review',lambda: expect(admin,'/api/destruction.php',{'action':'setStatus','ID':1,'Status':'review'}))
        check('destruction_approve',lambda: expect(admin,'/api/destruction.php',{'action':'setStatus','ID':1,'Status':'approved'}))
        check('destruction_print',lambda: expect(admin,'/destruction_print.php?id=1'))
        def destroy():
            response=expect(admin,'/api/destruction.php',{'action':'execute','ID':1})
            assert response['destroyed']==1,str(response)
        check('destruction_execute_isolated_fixture',destroy)
        check('purge_fixture_document',lambda: expect(admin,'/api/document.php',{'action':'purgeDocument','ID':'QA_EXPIRED'}))
        check('purged_fixture_not_found',lambda: expect(admin,'/api/document.php',{'action':'get','ID':'QA_EXPIRED'},code=404))
        check('folder_scope_restrict',lambda: expect(admin,'/api/users.php',{'action':'setFolderScope','ID':3,'FolderScope':'restricted','FolderIds':[2]}))
        check('folder_scope_hides_document',lambda: expect(viewer,'/api/document.php',{'action':'get','ID':'QA000001'},code=404))
        def scope_search():
            response=expect(viewer,'/api/search.php',{})
            assert response['total']==0,str(response)
        check('folder_scope_search_filtered',scope_search)
        check('invalid_document_id_denied',lambda: expect(staff,'/api/document.php',{'action':'createDocument','ID':'../invalid','Filename':'QA invalid','FolderId':1},code=400))
        check('logout',lambda: expect(viewer,'/logout.php'))
        check('session_invalid_after_logout',lambda: expect(viewer,'/api/init.php',code=401))
        check('user_create',lambda: expect(admin,'/api/users.php',{'action':'create','Username':'qa_aux','Password':env['QA_PASSWORD'],'Role':'staff'}))
        def account_lockout():
            client=Client(base)
            for _ in range(5):
                response=client.request('/api/auth.php',{'username':'qa_aux','password':'wrong'})[1]
                assert not response['success']
            response=client.request('/api/auth.php',{'username':'qa_aux','password':env['QA_PASSWORD']})[1]
            assert not response['success'],'Account was not locked'
            expect(admin,'/api/users.php',{'action':'unlock','ID':4})
            client.login('qa_aux',env['QA_PASSWORD'])
        check('login_lockout_and_admin_unlock',account_lockout)
        check('user_role_update',lambda: expect(admin,'/api/users.php',{'action':'updateRole','ID':4,'Role':'viewer'}))
        check('user_delete',lambda: expect(admin,'/api/users.php',{'action':'delete','ID':4}))
        def totp_flow():
            setup=expect(staff,'/api/security.php',{'action':'totpStart'})
            assert setup['qr'].startswith('data:image/svg+xml;base64,'),'Missing 2FA QR'
            secret=base64.b32decode(setup['secret'].replace(' ',''))
            digest=hmac.new(secret,(int(time.time())//30).to_bytes(8,'big'),hashlib.sha1).digest()
            offset=digest[-1]&15
            code=str((int.from_bytes(digest[offset:offset+4],'big')&0x7fffffff)%1000000).zfill(6)
            confirmed=expect(staff,'/api/security.php',{'action':'totpConfirm','code':code})
            assert len(confirmed['recoveryCodes'])==10,'Recovery codes missing'
            other=Client(base)
            status,result=other.request('/api/auth.php',{'username':'qa_staff','password':env['QA_PASSWORD']})
            assert result.get('need2fa') and not result['success'],'2FA not required'
            expect(other,'/api/auth.php',{'action':'verify2fa','code':code})
            recovery=Client(base)
            recovery.request('/api/auth.php',{'username':'qa_staff','password':env['QA_PASSWORD']})
            expect(recovery,'/api/auth.php',{'action':'verify2fa','code':confirmed['recoveryCodes'][0]})
            reused=Client(base)
            reused.request('/api/auth.php',{'username':'qa_staff','password':env['QA_PASSWORD']})
            status,result=reused.request('/api/auth.php',{'action':'verify2fa','code':confirmed['recoveryCodes'][0]})
            assert not result['success'],'Recovery code was reusable'
            expect(staff,'/api/security.php',{'action':'totpDisable','password':env['QA_PASSWORD']})
        check('totp_setup_login_recovery_one_time_disable',totp_flow)
        def digital_signature():
            signed=run/'signed.pdf'
            shutil.copy2(run/'qa.pdf',signed)
            code='require $argv[1]."/config/database.php"; require $argv[1]."/config/docsign.php"; setSetting($pdo,"sign_enabled","1"); $ok=signPdf($pdo,$argv[2]); setSetting($pdo,"sign_enabled","0"); exit($ok ? 0 : 1);'
            result=subprocess.run([opts.php,'-r',code,str(app),str(signed)],env=env,capture_output=True,timeout=60)
            assert result.returncode==0,result.stderr.decode(errors='replace')[:300]
            assert b'/ByteRange' in signed.read_bytes(),'PDF signature missing'
        check('pdf_digital_signature_isolated_certificate',digital_signature)
        def integrity():
            result=subprocess.run([opts.php,str(app/'tools/integrity_check.php')],env=env,capture_output=True,timeout=60)
            assert result.returncode==0 and b'problems 0' in result.stdout,result.stdout.decode(errors='replace')[:200]+result.stderr.decode(errors='replace')[:200]
        check('file_integrity_hashes',integrity)
        def backup():
            result=subprocess.run([opts.php,str(app/'tools/backup.php')],env=env,capture_output=True,timeout=60)
            assert result.returncode==0,result.stderr.decode(errors='replace')[:200]
            response=json.loads(result.stdout)
            assert response['state']=='done',str(response)
        check('backup_database_and_files',backup)
        def restore():
            result=subprocess.run([opts.php,str(app/'tools/restore_test.php')],env=env,capture_output=True,timeout=60)
            assert result.returncode==0 and result.stdout.startswith(b'OK'),result.stdout.decode(errors='replace')[:350]+result.stderr.decode(errors='replace')[:150]
        check('restore_backup_and_verify_hashes',restore)
    finally:
        server.terminate()
        server.wait(timeout=10)
        log.close()
        report={'database':env['DB_NAME'],'passed':sum(r['passed'] for r in RESULTS),'failed':sum(not r['passed'] for r in RESULTS),'results':RESULTS}
        (run/'results.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
        print(json.dumps({'passed':report['passed'],'failed':report['failed'],'artifacts':str(run)},ensure_ascii=False),flush=True)
    return 1 if any(not r['passed'] for r in RESULTS) else 0


if __name__ == '__main__':
    raise SystemExit(main())
