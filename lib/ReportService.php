<?php
/**
 * Report generation (CSV / Excel-compatible XML / print-ready PDF) for payouts, commissions,
 * members and reconciliation. Sensitive banking fields are masked unless the caller has
 * explicit permission.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class ReportService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Payout register, as raw rows.
     *
     * Deliberately raw (joined column names, one row per payout request): this is the shape the
     * payout report page renders and the shape exports need. It used to return human-labelled
     * keys ("Member ID", "Net Payable"), which forced every caller to know that — and
     * admin/payout-reports.php did not, so it read $row['net_amount'] off an array that only had
     * "Net Payable" and produced a wall of "Undefined array key" warnings. The labelled form is
     * now derived from this one by payoutExportRows(), so the two cannot drift apart again.
     *
     * @return list<array<string,mixed>>
     */
    public function payoutRows(array $filters = []): array
    {
        $sql = "SELECT p.*, m.member_code, m.name AS name, m.mobile, k.pan_number
                FROM payout_requests p
                JOIN members m ON m.id = p.member_id
                LEFT JOIN member_kyc k ON k.member_id = m.id
                WHERE 1=1";
        $params = [];
        if (!empty($filters['status'])) {
            $sql .= ' AND p.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND ' . $this->db->dateExpression('p.requested_at') . ' >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND ' . $this->db->dateExpression('p.requested_at') . ' <= :to';
            $params['to'] = $filters['to'];
        }
        if (!empty($filters['member'])) {
            $sql .= ' AND (m.member_code LIKE :member OR m.name LIKE :member)';
            $params['member'] = '%' . $filters['member'] . '%';
        }
        if (!empty($filters['bank'])) {
            $sql .= ' AND p.bank_snapshot LIKE :bank';
            $params['bank'] = '%' . $filters['bank'] . '%';
        }
        $sql .= ' ORDER BY p.id DESC';
        return $this->db->select($sql, $params);
    }

    /**
     * Payout register with labelled columns for exports, masked unless the caller may see banks.
     *
     * Kept because admin/reports.php exports these labels verbatim; it is built from
     * payoutRows() so there is a single query and a single notion of "a payout row".
     *
     * @return list<array<string,string>>
     */
    public function payouts(array $filters = [], bool $fullBank = false): array
    {
        $out = [];
        foreach ($this->payoutRows($filters) as $row) {
            $bank = json_decode((string) ($row['bank_snapshot'] ?? ''), true) ?: [];
            $out[] = [
                'Member ID' => (string) ($row['member_code'] ?? ''),
                'Member Name' => (string) ($row['name'] ?? ''),
                'Mobile' => (string) ($row['mobile'] ?? ''),
                'PAN' => $fullBank
                    ? (string) ($row['pan_number'] ?? '')
                    : Security::maskPan((string) ($row['pan_number'] ?? '')),
                'Bank Name' => (string) ($bank['bank_name'] ?? ''),
                'Account Holder' => (string) ($bank['account_holder_name'] ?? ''),
                'Account Number' => $fullBank
                    ? (string) ($bank['account_number'] ?? '')
                    : Security::maskAccount((string) ($bank['account_number'] ?? '')),
                'IFSC' => (string) ($bank['ifsc_code'] ?? ''),
                'Payout Amount' => number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
                'Deduction' => number_format((float) ($row['deduction_amount'] ?? 0), 2, '.', ''),
                'Net Payable' => number_format((float) ($row['net_amount'] ?? 0), 2, '.', ''),
                'Payout Status' => ucfirst((string) ($row['status'] ?? '')),
                'Transaction Reference' => (string) ($row['utr_number'] ?? ''),
                'Requested On' => (string) ($row['requested_at'] ?? ''),
                'Payout Date' => (string) ($row['paid_at'] ?? ''),
            ];
        }
        return $out;
    }

    /** Commission register used for reconciliation. */
    public function commissions(array $filters = []): array
    {
        $sql = "SELECT c.*, m.member_code, m.name AS member_name, o.order_number
                FROM commission_transactions c
                JOIN members m ON m.id = c.member_id
                LEFT JOIN orders o ON o.id = c.order_id WHERE 1=1";
        $params = [];
        if (!empty($filters['type'])) {
            $sql .= ' AND c.commission_type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND c.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND ' . $this->db->dateExpression('c.created_at') . ' >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND ' . $this->db->dateExpression('c.created_at') . ' <= :to';
            $params['to'] = $filters['to'];
        }
        $sql .= ' ORDER BY c.id DESC LIMIT 20000';
        $rows = $this->db->select($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            // Every read is defaulted. A report must degrade to a blank cell if the live schema
            // is missing a column, never spray "Undefined array key" across the page.
            $out[] = [
                'Transaction ID' => (string) ($row['transaction_code'] ?? ''),
                'Member ID' => (string) ($row['member_code'] ?? ''),
                'Member Name' => (string) ($row['member_name'] ?? ''),
                'Commission Type' => ucwords(str_replace('_', ' ', (string) ($row['commission_type'] ?? ''))),
                'Order No' => (string) ($row['order_number'] ?? ''),
                'Product' => (string) ($row['product_name'] ?? ''),
                'Buyer' => (string) ($row['buyer_name'] ?? ''),
                'Basis' => number_format((float) ($row['basis_amount'] ?? 0), 2, '.', ''),
                'Rate' => (($row['commission_type_rate'] ?? '') === 'percent'
                    ? ($row['rate_value'] ?? '') . '%'
                    : (string) ($row['rate_value'] ?? '')),
                'Amount' => number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
                'Status' => ucfirst((string) ($row['status'] ?? '')),
                'Date' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    public function members(array $filters = []): array
    {
        $sql = "SELECT m.*, s.member_code AS sponsor_code, w.available_balance, w.total_earned AS wallet_earned
                FROM members m
                LEFT JOIN members s ON s.id = m.sponsor_id
                LEFT JOIN wallet_accounts w ON w.member_id = m.id
                WHERE m.is_company = 0";
        $params = [];
        if (!empty($filters['status'])) {
            $sql .= ' AND m.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['kyc'])) {
            $sql .= ' AND m.kyc_status = :kyc';
            $params['kyc'] = $filters['kyc'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (m.name LIKE :q OR m.member_code LIKE :q OR m.mobile LIKE :q)';
            $params['q'] = '%' . $filters['search'] . '%';
        }
        $sql .= ' ORDER BY m.id DESC LIMIT 20000';
        $rows = $this->db->select($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'Member ID' => (string) ($row['member_code'] ?? ''),
                'Name' => (string) ($row['name'] ?? ''),
                'Mobile' => (string) ($row['mobile'] ?? ''),
                'Email' => (string) ($row['email'] ?? ''),
                'Sponsor' => (string) ($row['sponsor_code'] ?? ''),
                'Status' => ucfirst((string) ($row['status'] ?? '')),
                'KYC Status' => ucwords(str_replace('_', ' ', (string) ($row['kyc_status'] ?? ''))),
                'Direct Referrals' => (int) ($row['direct_referral_count'] ?? 0),
                'Downline' => (int) ($row['downline_count'] ?? 0),
                'Total Earned' => number_format((float) ($row['wallet_earned'] ?? 0), 2, '.', ''),
                'Available Balance' => number_format((float) ($row['available_balance'] ?? 0), 2, '.', ''),
                'Joined On' => (string) ($row['joined_at'] ?? ''),
            ];
        }
        return $out;
    }

    /** Excel-compatible export (SpreadsheetML 2003). No external library required. */
    public static function excelXml(string $sheetTitle, array $rows): string
    {
        $headers = $rows !== [] ? array_keys($rows[0]) : [];
        $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
        $xml .= '<Styles><Style ss:ID="hdr"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#247A3C" ss:Pattern="Solid"/></Style></Styles>' . "\n";
        $xml .= '<Worksheet ss:Name="' . $esc(mb_substr($sheetTitle, 0, 30)) . '"><Table>' . "\n";
        $xml .= '<Row>';
        foreach ($headers as $header) {
            $xml .= '<Cell ss:StyleID="hdr"><Data ss:Type="String">' . $esc($header) . '</Data></Cell>';
        }
        $xml .= '</Row>' . "\n";
        foreach ($rows as $row) {
            $xml .= '<Row>';
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $isNumeric = $value !== '' && is_numeric(str_replace(',', '', (string) $value)) && !ctype_alpha((string) $value);
                $type = $isNumeric ? 'Number' : 'String';
                $clean = $isNumeric ? str_replace(',', '', (string) $value) : (string) $value;
                $xml .= '<Cell><Data ss:Type="' . $type . '">' . $esc($clean) . '</Data></Cell>';
            }
            $xml .= '</Row>' . "\n";
        }
        $xml .= '</Table></Worksheet></Workbook>';
        return $xml;
    }

    /**
     * Minimal, dependency free PDF writer (A4 landscape table). Produces valid PDF 1.4 with
     * Helvetica text, suitable for payout statements and reports.
     */
    public static function pdf(string $title, array $rows, array $meta = []): string
    {
        $headers = $rows !== [] ? array_keys($rows[0]) : [];
        $lines = [];
        $lines[] = ['text' => $title, 'size' => 14, 'bold' => true];
        $metaLine = implode('   ', array_map(static fn ($k, $v) => $k . ': ' . $v, array_keys($meta), array_values($meta)));
        if ($metaLine !== '') {
            $lines[] = ['text' => $metaLine, 'size' => 8];
        }
        $lines[] = ['text' => '', 'size' => 6];

        $colWidths = [];
        foreach ($headers as $i => $header) {
            $maxLen = mb_strlen((string) $header);
            foreach (array_slice($rows, 0, 400) as $row) {
                $maxLen = max($maxLen, mb_strlen((string) ($row[$header] ?? '')));
            }
            $colWidths[$i] = min(120, max(38, $maxLen * 4.4));
        }
        $totalWidth = array_sum($colWidths);
        $maxWidth = 780;
        if ($totalWidth > $maxWidth && $totalWidth > 0) {
            $scale = $maxWidth / $totalWidth;
            foreach ($colWidths as $i => $w) {
                $colWidths[$i] = $w * $scale;
            }
        }

        $rowsPerPage = 34;
        $chunks = array_chunk($rows, $rowsPerPage) ?: [[]];
        $pages = [];
        foreach ($chunks as $pageIndex => $chunk) {
            $page = [];
            $page[] = ['text' => $title, 'size' => 13, 'bold' => true];
            if ($metaLine !== '') {
                $page[] = ['text' => $metaLine, 'size' => 8];
            }
            $page[] = ['text' => 'Page ' . ($pageIndex + 1) . ' of ' . count($chunks), 'size' => 8];
            $page[] = ['text' => '', 'size' => 4];
            $page[] = ['text' => self::pdfRowLine($headers, $colWidths), 'size' => 7, 'bold' => true];
            $page[] = ['text' => str_repeat('-', 150), 'size' => 7];
            foreach ($chunk as $row) {
                $values = [];
                foreach ($headers as $header) {
                    $values[] = (string) ($row[$header] ?? '');
                }
                $page[] = ['text' => self::pdfRowLine($values, $colWidths), 'size' => 7];
            }
            $pages[] = $page;
        }

        return self::buildPdf($pages, $maxWidth + 40);
    }

    private static function pdfRowLine(array $values, array $widths): string
    {
        $line = '';
        foreach ($values as $i => $value) {
            $width = (int) (($widths[$i] ?? 60) / 4.4);
            $value = str_replace(["\u{20B9}", "\r", "\n"], ['Rs.', ' ', ' '], (string) $value);
            $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
            $line .= str_pad(mb_substr($value, 0, $width), $width, ' ', STR_PAD_RIGHT) . ' ';
        }
        return rtrim($line);
    }

    private static function buildPdf(array $pages, float $pageWidth): string
    {
        $objects = [];
        $pageWidth = max(600, min(842, $pageWidth));
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $pageObjectStart = 5;
        $objects[] = ''; // pages placeholder (index 1)
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $pageObjects = [];
        $idx = $pageObjectStart;
        foreach ($pages as $page) {
            $content = "BT\n";
            $y = 570;
            foreach ($page as $line) {
                $font = !empty($line['bold']) ? '/F2' : '/F1';
                $size = $line['size'] ?? 9;
                $text = self::escapePdf($line['text']);
                $content .= sprintf("1 0 0 1 28 %.2f Tm\n%s %.2f Tf\n(%s) Tj\n", $y, $font, $size, $text);
                $y -= $size + 4.5;
            }
            $content .= "ET\n";
            $pageObjects[] = ['content' => $content, 'index' => $idx];
            $idx += 2;
        }
        $kids = [];
        foreach ($pageObjects as $pageObject) {
            $kids[] = $pageObject['index'] . ' 0 R';
        }
        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pageObjects) . ' >>';

        foreach ($pageObjects as $pageObject) {
            $contentIndex = $pageObject['index'] + 1;
            $stream = $pageObject['content'];
            $objects[$pageObject['index'] - 1] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $contentIndex
            );
            $objects[$contentIndex - 1] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            if ($object === '') {
                $object = '<< >>';
            }
            $offsets[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
        return $pdf;
    }

    private static function escapePdf(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
