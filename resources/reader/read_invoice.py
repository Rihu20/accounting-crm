"""Offline invoice extraction. PDF contents never leave this computer."""
import argparse
import calendar
import json
import os
import re
import subprocess
import sys
import tempfile
from datetime import datetime, timedelta
from decimal import Decimal, InvalidOperation
from pathlib import Path

from pypdf import PdfReader

CURRENCIES = ('USD', 'INR', 'EUR', 'JPY', 'GBP', 'KRW', 'CNY', 'CAD', 'AUD', 'CHF', 'SGD', 'HKD')
DATE_PATTERN = r'(?:\d{4}[/.-]\d{1,2}[/.-]\d{1,2}|\d{1,2}[/.-]\d{1,2}[/.-]\d{2,4}|[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4}|\d{1,2}\s+[A-Za-z]{3,9},?\s+\d{4})'
NUMBER_PATTERN = r'\d[\d,\u00a0 ]*(?:\.\d{1,2})?|\d[\d. ]*,\d{2}'


def date_value(raw):
    raw = re.sub(r'\s+', ' ', raw.strip().replace(',', ''))
    for fmt in ('%Y/%m/%d', '%Y-%m-%d', '%Y.%m.%d', '%d/%m/%Y', '%d-%m-%Y', '%d.%m.%Y', '%d/%m/%y', '%d.%m.%y', '%B %d %Y', '%b %d %Y', '%d %B %Y', '%d %b %Y'):
        try:
            return datetime.strptime(raw, fmt).date().isoformat()
        except ValueError:
            pass
    return None


def amount_value(raw):
    raw = re.sub(r'[^\d.,-]', '', raw)
    if not raw:
        return None
    if ',' in raw and '.' in raw:
        if raw.rfind(',') > raw.rfind('.'):
            raw = raw.replace('.', '').replace(',', '.')
        else:
            raw = raw.replace(',', '')
    elif ',' in raw:
        raw = raw.replace(',', '.') if re.fullmatch(r'\d+,\d{2}', raw) else raw.replace(',', '')
    try:
        return str(Decimal(raw).quantize(Decimal('0.01')))
    except InvalidOperation:
        return None


def first_match(pattern, text, flags=re.I | re.M):
    match = re.search(pattern, text, flags)
    return match.group(1).strip() if match else None


def currency_value(text):
    explicit = re.search(r'\b(' + '|'.join(CURRENCIES) + r')\b', text, re.I)
    if explicit:
        return explicit.group(1).upper()
    for pattern, code in [(r'US\s*\$|U\.?\s*S\.?\s*Dollar', 'USD'), (r'₹|\bRupees\b|\bRs\.', 'INR'), (r'€|\bEuros?\b', 'EUR'), (r'Japanese\s+Yen|\bYen\b', 'JPY'), (r'£|Pounds?\s+Sterling', 'GBP')]:
        if re.search(pattern, text, re.I):
            return code
    return None


def extract_fields(text, company_name='AnalystIP'):
    text = text.replace('\r', '').replace('\u3000', ' ').replace('\u00a0', ' ')
    lines = [re.sub(r'[ \t]+', ' ', line).strip() for line in text.splitlines() if line.strip()]
    text = '\n'.join(lines)
    data = dict.fromkeys(['vendor_name', 'invoice_no', 'invoice_date', 'due_date', 'currency', 'amount', 'tax_amount', 'description', 'ref_no'])
    warnings = []
    evidence = {}
    details = {}
    data['invoice_no'] = first_match(r'\b(?:debit\s+note|tax\s+invoice|invoice|bill)\s*(?:number\b|no\b\.?|#)[ \t]*[:#-]?[ \t]*((?=[A-Z0-9/._-]*\d)[A-Z0-9][A-Z0-9/._-]*)', text)
    for prefix in [r'\binvoice\s+date\s*[:.-]?\s*', r'^DATE\s*[:.-]\s*', r'^Date\s+']:
        raw = first_match(prefix + '(' + DATE_PATTERN + ')', text)
        if raw and date_value(raw):
            data['invoice_date'] = date_value(raw)
            evidence['invoice_date'] = raw
            break
    raw_due = first_match(r'\b(?:due\s+date|payment\s+due(?:\s+date)?)\s*[:.-]?\s*(' + DATE_PATTERN + ')', text)
    if raw_due:
        data['due_date'] = date_value(raw_due)
    terms = first_match(r'(?:\bnet\s+|due\s+within\s+|within\s+)(\d{1,3})\s*days?', text)
    if terms:
        details['payment_terms'] = terms + ' days'
        if not data['due_date'] and data['invoice_date']:
            if re.search(r'from\s+(?:the\s+)?date\s+of\s+receipt', text, re.I):
                warnings.append('Payment term starts from receipt. Enter the due date after confirming when the invoice was received.')
            else:
                data['due_date'] = (datetime.fromisoformat(data['invoice_date']) + timedelta(days=int(terms))).date().isoformat()
                warnings.append('Due date calculated from the invoice date and stated payment term. Please verify it.')
    totals = []
    for index, line in enumerate(lines):
        label = re.search(r'\b(balance\s+due|amount\s+due|total\s+(?:amount\s+)?(?:payable|due)|grand\s+total|total(?:\s+amount)?)\b', line, re.I)
        if not label or re.search(r'\bsubtotal\b', line, re.I):
            continue
        tail = line[label.end():]
        if not re.search(r'\d', tail) and index + 1 < len(lines):
            tail += ' ' + lines[index + 1]
        numbers = re.findall(r'\d[\d.,]*', tail)
        if numbers:
            value = amount_value(numbers[-1])
            if value is not None:
                rank = 3 if re.search(r'balance|due|payable|grand', label.group(), re.I) else 1
                totals.append((rank, currency_value(tail), value, line))
    if totals:
        best_rank = max(item[0] for item in totals)
        candidates = [item for item in totals if item[0] == best_rank]
        values = {item[2] for item in candidates}
        if len(values) == 1:
            chosen = next((item for item in candidates if item[1]), candidates[-1])
            data['amount'], data['currency'] = chosen[2], chosen[1]
            evidence['amount'] = chosen[3]
        else:
            warnings.append('More than one different invoice total was found. Enter the correct total manually.')
    data['currency'] = data['currency'] or currency_value('\n'.join(line for line in lines if not re.search(r'account|currencies|SWIFT', line, re.I)))
    vendor = first_match(r'^(?:Beneficiary\s+Name|Name\s+of\s+(?:the\s+)?Account|Account\s+Name|Supplier|Vendor|From)\s*[:：]\s*(.+?)(?:\s+(?:SWIFT|Account\s+No|Address)\b|$)', text)
    if not vendor:
        vendor = first_match(r'Name\s+of\s+Account\s*:\s*(.+?)(?:\s+Address\s+of\s+the\s+Branch|$)', text)
    if not vendor:
        business = r'\b(?:Patent\s+(?:Firm|Attorney|Office)|Law\s+(?:Firm|Office)|LLP|LLC|Ltd\.?|Limited|Corporation|Corp\.?|GmbH|SARL|Pte\.?)\b'
        for line in lines:
            if re.search(business, line, re.I) and not re.search(r'bank:|account|beneficiary|\bRe:|Applicant', line, re.I) and not re.search(re.escape(company_name.split()[0]), line, re.I):
                vendor = line
                break
    data['vendor_name'] = vendor
    details['country'] = 'Japan' if re.search(r'\bJAPAN\b', text) else ('South Korea' if re.search(r'\bKOREA\b', text) else None)
    for key, pattern in {
        'your_ref': r'Your\s+Ref\.?\s*:\s*([^\s/]+)',
        'vendor_ref': r'(?:Our|SPP)\s+Ref\.?\s*:\s*([^\s]+)',
        'application_no': r'(?:Patent\s+Application|JP\s+application|Application)\s+No\.?\s*:\s*([\d-]+)',
        'pct_no': r'\b(PCT/[A-Z]{2}\d{4}/\d+)\b',
        'beneficiary': r'Beneficiary\s+Name\s*:\s*(.+)',
        'bank_name': r'(?:Beneficiary\s+Bank\s+Name|^Bank)\s*:\s*([^\n(/]+)',
        'bank_account': r'(?:Beneficiary\s+Account\s+No\.?|Account\s+No\.?)\s*:\s*([\d-]+)',
        'swift': r'(?:SWIFT(?:\s+(?:Code|code))?)\s*:\s*([A-Z0-9]{8,11})',
        'tax_id': r'(?:Registration\s+No\.?|GSTIN|Tax\s+ID)\s*:\s*([A-Z0-9-]+)',
    }.items():
        value = first_match(pattern, text)
        if value:
            details[key] = value
    explicit_ref = first_match(r'^(?:CRM\s+)?Ref\s*(?:No\.?)?\s*:\s*(\d{4})(?!\d)', text)
    data['ref_no'] = explicit_ref
    tax_values = []
    for line in lines:
        if re.match(r'^(?:GST|IGST|CGST|SGST|VAT|Tax(?:\s+amount)?)\b', line, re.I) and not re.search(r'(?:GSTIN|Tax\s+ID|Invoice)', line, re.I):
            matches = re.findall(r'\d[\d.,]*', line)
            if matches and not re.search(r'%\s*$', line):
                tax_values.append(Decimal(amount_value(matches[-1]) or '0'))
    if tax_values:
        data['tax_amount'] = str(sum(tax_values).quantize(Decimal('0.01')))
    description = first_match(r'^(?:Title|Re)\s*:\s*(.+)', text)
    if description:
        data['description'] = description
    elif details.get('application_no'):
        data['description'] = 'Services for application ' + details['application_no']
    for label in ['Official Fees', 'Professional Fees', 'Legal Service Fee']:
        if re.search(re.escape(label), text, re.I):
            details.setdefault('fee_types', []).append(label)
    for key in ('vendor_name', 'invoice_no', 'invoice_date', 'currency', 'amount'):
        if not data[key]:
            warnings.append(key.replace('_', ' ').capitalize() + ' could not be identified. Please enter it.')
    return {'data': data, 'details': {key: value for key, value in details.items() if value}, 'warnings': warnings, 'evidence': evidence, 'text': text[:80000]}


def read_pdf(path):
    pdf = PdfReader(path)
    if pdf.is_encrypted and not pdf.decrypt(''):
        raise ValueError('This PDF is password protected. Upload an unlocked copy.')
    if len(pdf.pages) > 25:
        raise ValueError('Upload a PDF of 25 pages or fewer.')
    texts = []
    ocr_pages = []
    for index, page in enumerate(pdf.pages):
        text = page.extract_text() or ''
        if len(re.sub(r'\W', '', text)) < 40:
            ocr_pages.append(index)
        texts.append(text)
    if ocr_pages:
        if os.name != 'nt':
            raise ValueError('This scanned PDF needs local OCR. OCR is configured for this Windows computer.')
        import pypdfium2 as pdfium
        rendered = pdfium.PdfDocument(str(path))
        powershell = Path(os.environ.get('WINDIR', r'C:\Windows')) / 'System32/WindowsPowerShell/v1.0/powershell.exe'
        with tempfile.TemporaryDirectory(prefix='crm-ocr-') as folder:
            for index in ocr_pages:
                image = Path(folder) / f'page-{index}.png'
                rendered[index].render(scale=2.5).to_pil().save(image)
                result = subprocess.run([str(powershell), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', str(Path(__file__).with_name('windows_ocr.ps1')), '-ImagePath', str(image)], capture_output=True, timeout=40, creationflags=subprocess.CREATE_NO_WINDOW)
                if result.returncode:
                    raise ValueError('The scanned PDF could not be read by Windows OCR. Upload a clearer copy or enter its fields manually.')
                texts[index] = result.stdout.decode('utf-8-sig').strip()
        rendered.close()
    result = extract_fields('\n'.join(texts))
    result['reader'] = 'local-ocr' if ocr_pages else 'local-text'
    if ocr_pages:
        result['warnings'].insert(0, 'Scanned pages were read using OCR. Check numbers and dates against the PDF.')
    return result


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    arguments = argparse.ArgumentParser()
    arguments.add_argument('file')
    args = arguments.parse_args()
    try:
        print(json.dumps(read_pdf(Path(args.file)), ensure_ascii=False))
    except Exception as error:
        message = str(error) if isinstance(error, ValueError) else 'The local reader could not open this PDF. Try another copy or use manual entry.'
        print(json.dumps({'error': message}))
        sys.exit(1)
