<?php
/**
 * Download/print watermarks and PDF digital signatures.
 *
 * Signatures use the organisation's certificate when an admin installs one (.p12 from a CA, e.g. a Thai
 * government or commercial CA). Until then a self-signed certificate is generated: PDF readers will show the
 * signature and detect any later change to the file, but mark the signer as "not trusted".
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use setasign\Fpdi\Fpdi;

/** FPDI with rotated text (classic FPDF "Rotate" extension). */
class WatermarkPdf extends Fpdi {
    protected $angle = 0;

    public function Rotate($angle, $x = -1, $y = -1) {
        if ($x == -1) $x = $this->x;
        if ($y == -1) $y = $this->y;
        if ($this->angle != 0) $this->_out('Q');
        $this->angle = $angle;
        if ($angle != 0) {
            $a = $angle * M_PI / 180;
            $c = cos($a);
            $s = sin($a);
            $cx = $x * $this->k;
            $cy = ($this->h - $y) * $this->k;
            $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', $c, $s, -$s, $c, $cx, $cy, -$cx, -$cy));
        }
    }

    protected function _endpage() {
        if ($this->angle != 0) {
            $this->angle = 0;
            $this->_out('Q');
        }
        parent::_endpage();
    }
}

/** ASCII only: the built-in PDF fonts have no Thai glyphs. */
function asciiOnly($s) {
    return trim(preg_replace('/[^\x20-\x7E]/', '', (string)$s)) ?: '-';
}

/**
 * Write $dest = $src with a diagonal watermark and a footer line on every page. Returns false when the PDF
 * cannot be parsed (the caller then refuses the download rather than handing out an unmarked copy).
 */
function watermarkPdf($src, $dest, $diagonal, $footer) {
    try {
        $pdf = new WatermarkPdf();
        $pdf->SetAutoPageBreak(false);
        $n = $pdf->setSourceFile($src);
        for ($i = 1; $i <= $n; $i++) {
            $tpl = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);

            $w = $size['width'];
            $h = $size['height'];
            $pdf->SetFont('Helvetica', 'B', max(18, min(40, $w / 9)));
            $pdf->SetTextColor(215, 215, 215);
            $text = asciiOnly($diagonal);
            $tw = $pdf->GetStringWidth($text);
            $pdf->Rotate(atan2($h, $w) * 180 / M_PI, $w / 2, $h / 2);
            $pdf->Text($w / 2 - $tw / 2, $h / 2, $text);
            $pdf->Rotate(0);

            $pdf->SetFont('Helvetica', '', 7);
            $pdf->SetTextColor(120, 120, 120);
            $pdf->Text(6, $h - 4, asciiOnly($footer) . '  |  page ' . $i . '/' . $n);
        }
        $pdf->Output('F', $dest);
        return true;
    } catch (Throwable $e) {
        error_log("watermark failed for $src: " . $e->getMessage());
        return false;
    }
}

// ---------- digital signatures ----------

function signingDir() {
    if (!is_dir(SIGN_KEY_DIR)) mkdir(SIGN_KEY_DIR, 0700, true);
    return SIGN_KEY_DIR;
}

function signingCertInfo() {
    $cert = signingDir() . DIRECTORY_SEPARATOR . 'sign_cert.pem';
    if (!is_file($cert)) return null;
    $x = openssl_x509_parse(file_get_contents($cert));
    return $x ? [
        'subject' => $x['subject']['CN'] ?? '',
        'issuer' => $x['issuer']['CN'] ?? '',
        'selfSigned' => ($x['subject'] ?? null) == ($x['issuer'] ?? null),
        'validTo' => date('Y-m-d', $x['validTo_time_t']),
    ] : null;
}

/** Create a self-signed certificate for the organisation if none is installed yet. */
function ensureSigningCert($pdo) {
    $dir = signingDir();
    $certFile = "$dir/sign_cert.pem";
    $keyFile = "$dir/sign_key.pem";
    if (is_file($certFile) && is_file($keyFile)) return true;

    $cfg = ['config' => OPENSSL_CONF_PATH, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($cfg);
    if (!$key) return false;
    $org = asciiOnly(getSetting($pdo, 'org_name_en', 'EDMS Organisation'));
    $csr = openssl_csr_new(['commonName' => "$org Document Signing", 'organizationName' => $org, 'countryName' => 'TH'], $key, $cfg);
    $x509 = openssl_csr_sign($csr, null, $key, 3650, $cfg, random_int(1, PHP_INT_MAX));
    if (!$x509) return false;
    openssl_x509_export($x509, $certPem);
    openssl_pkey_export($key, $keyPem, null, $cfg);
    file_put_contents($certFile, $certPem);
    file_put_contents($keyFile, $keyPem);
    return true;
}

/** Install a certificate from a .p12/.pfx file issued by a certificate authority. */
function installSigningP12($data, $password) {
    if (!openssl_pkcs12_read($data, $certs, $password)) return 'อ่านไฟล์ใบรับรองไม่ได้ หรือรหัสผ่านไม่ถูกต้อง';
    $dir = signingDir();
    file_put_contents("$dir/sign_cert.pem", $certs['cert']);
    openssl_pkey_export($certs['pkey'], $keyPem, null, ['config' => OPENSSL_CONF_PATH]);
    file_put_contents("$dir/sign_key.pem", $keyPem);
    if (!empty($certs['extracerts'])) file_put_contents("$dir/sign_chain.pem", implode("\n", $certs['extracerts']));
    return null;
}

/** Sign the PDF in place. The signing time is embedded in the signature. Returns true on success. */
function signPdf($pdo, $path) {
    if (getSetting($pdo, 'sign_enabled', '0') !== '1' || !ensureSigningCert($pdo)) return false;
    $dir = signingDir();
    $tmp = $path . '.signing';
    try {
        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $n = $pdf->setSourceFile($path);
        for ($i = 1; $i <= $n; $i++) {
            $tpl = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
        }
        $chain = is_file("$dir/sign_chain.pem") ? "$dir/sign_chain.pem" : '';
        $pdf->setSignature('file://' . realpath("$dir/sign_cert.pem"), 'file://' . realpath("$dir/sign_key.pem"), '', $chain, 2, [
            'Name' => getSetting($pdo, 'org_name', 'EDMS'),
            'Reason' => 'Certified electronic copy',
            'Location' => 'EDMS',
            'ContactInfo' => '',
        ]);
        $pdf->Output($tmp, 'F');
        rename($tmp, $path);
        return true;
    } catch (Throwable $e) {
        @unlink($tmp);
        error_log("signing failed for $path: " . $e->getMessage());
        return false;
    }
}
