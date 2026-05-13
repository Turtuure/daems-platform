<?php
/**
 * Backstage Governance — CSV import (Nordea bank statement).
 *
 * Two-step flow:
 *   1. Upload CSV → POST /api/backstage/governance/billing/payments/import-csv (multipart)
 *      Backend parses + matches; returns preview JSON.
 *   2. User ticks matches → POST /api/backstage/governance/billing/payments/import-csv/confirm
 *      Backend dispatches RecordManualPayment per match with method='csv_import'.
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'CSV-tuonti';
$activePage  = 'governance-billing';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.billing'), 'href' => '/backstage/governance/billing'],
    ['label' => 'CSV-tuonti'],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">CSV-tuonti — pankkitiliote</h1>
        <p class="page-header__subtitle">
            Lataa Nordea-tiliote CSV-muodossa. Järjestelmä yrittää löytää viitenumeroon perustuen
            avoimet laskut ja ehdottaa täsmäykset.
        </p>
    </div>
</div>

<section class="billing-import">
    <form id="import-form" enctype="multipart/form-data" class="billing-import__form">
        <label>CSV-tiedosto
            <input type="file" name="csv" accept=".csv,text/csv" required>
        </label>
        <button type="submit" class="btn btn--primary">Esikatsele</button>
    </form>

    <section id="preview" hidden>
        <h3>Esikatselu — täsmäysehdotukset</h3>
        <p><strong id="high-confidence-count">0</strong> korkea-luottamus täsmäystä.</p>
        <table class="billing-import__preview">
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all"></th>
                    <th>Rivi</th>
                    <th>Maksaja</th>
                    <th>Viite</th>
                    <th>Summa</th>
                    <th>Täsmäys</th>
                    <th>Luotettavuus</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
        <button type="button" id="confirm-btn" class="btn btn--primary">Vahvista valitut</button>
    </section>

    <section id="results" hidden>
        <h3>Tuonti valmis</h3>
        <p>
            <strong id="applied-count">0</strong> laskua merkitty maksetuksi.
            <strong id="error-count">0</strong> virhettä.
        </p>
        <details>
            <summary>Yksityiskohdat</summary>
            <pre id="results-details"></pre>
        </details>
    </section>
</section>

<link rel="stylesheet" href="/backstage/pages/governance/billing-import.css">
<script src="/backstage/pages/governance/billing-import.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
