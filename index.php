<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pdo = db();
$rows = $pdo->query(
    'SELECT pr.*,
            (SELECT COUNT(*) FROM purchase_request_items pri WHERE pri.purchase_request_id = pr.id) AS items_count
     FROM purchase_requests pr
     ORDER BY pr.id DESC
     LIMIT 100'
)->fetchAll();

function e(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PR Converter</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <style>
        :root {
            --ink: #0f172a;
            --muted: #475569;
            --brand: #0f766e;
            --accent: #b45309;
            --paper: #f8fafc;
            --card: #ffffff;
            --line: #e2e8f0;
        }

        body {
            background:
                radial-gradient(1200px 500px at 15% -5%, #ccfbf1 0%, transparent 60%),
                radial-gradient(900px 400px at 95% -10%, #fde68a 0%, transparent 50%),
                linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            color: var(--ink);
        }

        .glass {
            backdrop-filter: blur(6px);
            background: rgba(255, 255, 255, 0.82);
        }

        .header-shadow {
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.08);
        }

        .fade-in {
            animation: rise 0.55s ease forwards;
        }

        @keyframes rise {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body class="min-h-screen">
    <main class="max-w-7xl mx-auto px-4 py-8 md:py-12">
        <section class="glass header-shadow rounded-3xl border border-white/70 p-6 md:p-8 fade-in">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                <div>
                    <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Document Intelligence</p>
                    <h1 class="text-3xl md:text-4xl font-semibold mt-2">Purchase Request Converter</h1>
                    <p class="text-slate-600 mt-3 max-w-2xl">Upload a PR PDF, extract required fields, and persist everything to SQLite for fast retrieval and downstream processing.</p>
                </div>
                <div class="rounded-2xl border border-teal-200 bg-teal-50 px-4 py-3">
                    <p class="text-xs uppercase tracking-widest text-teal-800 text-center">Records Stored</p>
                    <p id="recordsCount" class="text-2xl font-semibold text-teal-900 text-center"><?= count($rows) ?></p>
                </div>
            </div>
        </section>

        <section class="mt-8 grid grid-cols-1 lg:grid-cols-5 gap-6">
            <div class="lg:col-span-1 bg-white rounded-3xl border border-slate-200 shadow-sm p-6 fade-in">
                <h2 class="text-xl font-semibold">Upload PDF</h2>
                <p class="text-sm text-slate-600 mt-1">Supports text-based and scanned PR forms (OCR fallback).</p>

                <form id="uploadForm" class="mt-6 space-y-4" enctype="multipart/form-data">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2" for="pdf">PDF File</label>
                        <input id="pdf" name="pdf" type="file" accept="application/pdf,.pdf" required class="block w-full rounded-xl border border-slate-300 px-3 py-2 text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-slate-700" />
                    </div>

                    <button id="submitBtn" type="submit" class="w-full rounded-xl bg-slate-900 px-4 py-3 text-white font-medium hover:bg-slate-800 transition">Process & Save</button>
                </form>

                <div id="status" class="hidden mt-4 rounded-xl border px-4 py-3 text-sm"></div>

                <div id="result" class="hidden mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <h3 class="text-sm font-semibold text-slate-800 uppercase tracking-wider">Latest Parsed Data</h3>
                    <dl id="resultList" class="mt-3 grid grid-cols-1 gap-2 text-sm"></dl>
                </div>
            </div>

            <div class="lg:col-span-4 bg-white rounded-3xl border border-slate-200 shadow-sm p-6 fade-in">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold">Saved Records</h2>
                    <span class="text-xs text-slate-500">Latest 100</span>
                </div>

                <div class="mt-4 overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-200">
                                <th class="py-2 pr-4">ID</th>
                                <th class="py-2 pr-4">PR No.</th>
                                <th class="py-2 pr-4">Date</th>
                                <th class="py-2 pr-4">Fund Cluster</th>
                                <th class="py-2 pr-4 text-center">Items</th>
                                <th class="py-2 pr-4 text-right">Total Cost</th>
                                <th class="py-2 pr-4">Requested By</th>
                                <th class="py-2 pr-4 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="8" class="py-8 text-center text-slate-500">No records yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr class="border-b border-slate-100 hover:bg-slate-50/70" data-row-id="<?= (int) $row['id'] ?>">
                                        <td class="py-2 pr-4 font-medium"><?= (int) $row['id'] ?></td>
                                        <td class="py-2 pr-4">
                                            <a class="font-medium text-teal-700 hover:text-teal-900 underline underline-offset-2" href="export.php?id=<?= (int) $row['id'] ?>" title="Download XLSX">
                                                <?= e($row['pr_no']) ?>
                                            </a>
                                        </td>
                                        <td class="py-2 pr-4"><?= e($row['request_date']) ?></td>
                                        <td class="py-2 pr-4"><?= e($row['fund_cluster']) ?></td>
                                        <td class="py-2 pr-4 text-center">
                                            <button
                                                type="button"
                                                class="js-items-btn inline-flex items-center rounded-lg border border-teal-200 bg-teal-50 px-2.5 py-1 text-xs font-medium text-teal-800 hover:bg-teal-100"
                                                data-id="<?= (int) $row['id'] ?>"
                                                data-pr="<?= e($row['pr_no']) ?>"
                                            >
                                                <?= (int) ($row['items_count'] ?? 0) ?>
                                            </button>
                                        </td>
                                        <td class="js-pr-total py-2 pr-4 text-right"><?= $row['total_cost'] !== null ? number_format((float) $row['total_cost'], 2) : '-' ?></td>
                                        <td class="py-2 pr-4"><?= e($row['requested_by']) ?></td>
                                        <td class="py-2 pr-4 text-center">
                                            <button
                                                type="button"
                                                class="js-delete-btn inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700 hover:bg-rose-100"
                                                data-id="<?= (int) $row['id'] ?>"
                                                data-pr="<?= e($row['pr_no']) ?>"
                                            >
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <div id="itemsModal" class="fixed inset-0 z-40 hidden">
        <div class="js-close-modal absolute inset-0 bg-slate-900/45" data-modal="#itemsModal"></div>
        <div class="relative z-10 h-full overflow-y-auto">
            <div class="max-w-5xl mx-auto px-4 py-8">
            <div class="rounded-2xl bg-white border border-slate-200 shadow-xl max-h-[calc(100vh-4rem)] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
                    <h3 id="itemsModalTitle" class="text-lg font-semibold text-slate-900">PR Items</h3>
                    <button type="button" id="closeItemsModal" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">Close</button>
                </div>
                <div class="p-6">
                    <div class="overflow-auto max-h-[60vh]">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-slate-500 border-b border-slate-200">
                                    <th class="py-2 pr-4">#</th>
                                    <th class="py-2 pr-4">Stock/Property No.</th>
                                    <th class="py-2 pr-4">Unit</th>
                                    <th class="py-2 pr-4">Item Description</th>
                                    <th class="py-2 pr-4 text-right">Quantity</th>
                                    <th class="py-2 pr-4 text-right">Unit Cost</th>
                                    <th class="py-2 pr-4 text-right">Total Cost</th>
                                    <th class="py-2 pr-4 text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="itemsModalBody">
                                <tr>
                                    <td colspan="8" class="py-6 text-center text-slate-500">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-5 border-t border-slate-200 pt-4">
                        <div class="flex items-center justify-between">
                            <h4 id="itemFormTitle" class="text-sm font-semibold text-slate-900">Add New Item</h4>
                            <button type="button" id="resetItemFormBtn" class="hidden rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Cancel Edit</button>
                        </div>
                        <form id="itemForm" class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                            <input type="hidden" id="itemFormItemId" name="item_id" value="">
                            <div>
                                <label class="block text-xs text-slate-600 mb-1" for="itemStockNo">Stock/Property No.</label>
                                <input id="itemStockNo" name="stock_property_no" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-slate-600 mb-1" for="itemUnit">Unit</label>
                                <input id="itemUnit" name="unit" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs text-slate-600 mb-1" for="itemDescription">Item Description</label>
                                <textarea id="itemDescription" name="item_description" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-600 mb-1" for="itemQty">Quantity</label>
                                <input id="itemQty" name="quantity" type="number" step="0.01" min="0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-slate-600 mb-1" for="itemUnitCost">Unit Cost</label>
                                <input id="itemUnitCost" name="unit_cost" type="number" step="0.01" min="0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-slate-600 mb-1" for="itemTotalCost">Total Cost</label>
                                <input id="itemTotalCost" name="total_cost" type="number" step="0.01" min="0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                            <div class="flex items-end">
                                <button type="submit" id="saveItemBtn" class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-800">Save Item</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>

    <div id="deleteModal" class="fixed inset-0 z-50 hidden">
        <div class="js-close-modal absolute inset-0 bg-slate-900/45" data-modal="#deleteModal"></div>
        <div class="relative z-10 max-w-md mx-auto px-4 py-20">
            <div class="rounded-2xl bg-white border border-slate-200 shadow-xl p-6">
                <h3 class="text-lg font-semibold text-slate-900">Delete Entry</h3>
                <p id="deleteModalText" class="text-sm text-slate-600 mt-2">Are you sure you want to delete this entry?</p>
                <div class="mt-6 flex items-center justify-end gap-3">
                    <button type="button" id="cancelDeleteBtn" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="button" id="confirmDeleteBtn" class="rounded-lg bg-rose-600 px-4 py-2 text-sm text-white hover:bg-rose-700">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <div id="deleteItemModal" class="fixed inset-0 z-50 hidden">
        <div class="js-close-modal absolute inset-0 bg-slate-900/45" data-modal="#deleteItemModal"></div>
        <div class="relative z-10 max-w-md mx-auto px-4 py-20">
            <div class="rounded-2xl bg-white border border-slate-200 shadow-xl p-6">
                <h3 class="text-lg font-semibold text-slate-900">Delete Item</h3>
                <p id="deleteItemModalText" class="text-sm text-slate-600 mt-2">Are you sure you want to delete this item?</p>
                <div class="mt-6 flex items-center justify-end gap-3">
                    <button type="button" id="cancelDeleteItemBtn" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="button" id="confirmDeleteItemBtn" class="rounded-lg bg-rose-600 px-4 py-2 text-sm text-white hover:bg-rose-700">Delete Item</button>
                </div>
            </div>
        </div>
    </div>

    <div id="processConfirmModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-slate-900/45"></div>
        <div class="relative z-10 max-w-md mx-auto px-4 py-20">
            <div class="rounded-2xl bg-white border border-slate-200 shadow-xl p-6">
                <h3 class="text-lg font-semibold text-slate-900">Processing Complete</h3>
                <p id="processConfirmText" class="text-sm text-slate-600 mt-2">Found 0 item(s). Continue saving to database?</p>
                <div class="mt-6 flex items-center justify-end gap-3">
                    <button type="button" id="cancelProcessBtn" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="button" id="continueProcessBtn" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-800">Continue</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const labels = {
            fund_cluster: 'Fund Cluster',
            pr_no: 'PR No.',
            responsibility_center_code: 'Responsibility Center Code',
            request_date: 'Date',
            unit: 'Unit',
            item_description: 'Item Description',
            quantity: 'Quantity',
            unit_cost: 'Unit Cost',
            total_cost: 'Total Cost',
            requested_by: 'Requested by',
            designation1: 'Designation1',
            approved_by: 'Approved by',
            designation2: 'Designation2'
        };
        let pendingDelete = null;
        let pendingProcess = null;
        let currentItemsContext = { prId: null, prNo: '' };
        let pendingItemDelete = null;
        let itemFormMode = 'create';
        let currentItems = [];

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function showStatus(kind, message) {
            const status = $('#status');
            status.removeClass('hidden border-red-200 bg-red-50 text-red-700 border-green-200 bg-green-50 text-green-700 border-slate-200 bg-slate-50 text-slate-700');

            if (kind === 'error') {
                status.addClass('border-red-200 bg-red-50 text-red-700');
            } else if (kind === 'success') {
                status.addClass('border-green-200 bg-green-50 text-green-700');
            } else {
                status.addClass('border-slate-200 bg-slate-50 text-slate-700');
            }

            status.text(message);
        }

        function ensureEmptyState() {
            const tbody = $('table tbody');
            if (tbody.find('tr[data-row-id]').length > 0) {
                return;
            }

            if (tbody.find('tr').length === 0) {
                tbody.append('<tr><td colspan="8" class="py-8 text-center text-slate-500">No records yet.</td></tr>');
            }
        }

        function renderResult(data) {
            const result = $('#result');
            const list = $('#resultList');
            list.empty();

            Object.keys(labels).forEach((key) => {
                const value = data[key] ?? '';
                const formatted = (value === null || value === '') ? '-' : value;
                list.append(`<div class="grid grid-cols-3 gap-2"><dt class="text-slate-500">${labels[key]}</dt><dd class="col-span-2 font-medium text-slate-900">${formatted}</dd></div>`);
            });

            result.removeClass('hidden');
        }

        function openModal(id) {
            $(id).removeClass('hidden');
            $('body').addClass('overflow-hidden');
        }

        function closeModal(id) {
            $(id).addClass('hidden');
            if (
                $('#itemsModal').hasClass('hidden') &&
                $('#deleteModal').hasClass('hidden') &&
                $('#deleteItemModal').hasClass('hidden') &&
                $('#processConfirmModal').hasClass('hidden')
            ) {
                $('body').removeClass('overflow-hidden');
            }
        }

        function renderItems(items) {
            const tbody = $('#itemsModalBody');
            tbody.empty();

            if (!Array.isArray(items) || items.length === 0) {
                tbody.append('<tr><td colspan="8" class="py-6 text-center text-slate-500">No item rows found.</td></tr>');
                return;
            }

            items.forEach((item, idx) => {
                const qty = item.quantity === null || item.quantity === '' ? '-' : Number(item.quantity).toLocaleString();
                const unitCost = item.unit_cost === null || item.unit_cost === '' ? '-' : Number(item.unit_cost).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const totalCost = item.total_cost === null || item.total_cost === '' ? '-' : Number(item.total_cost).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                tbody.append(
                    `<tr class="border-b border-slate-100" data-item-id="${item.id}">
                        <td class="py-2 pr-4 text-slate-600">${idx + 1}</td>
                        <td class="py-2 pr-4">${escapeHtml(item.stock_property_no || '-')}</td>
                        <td class="py-2 pr-4">${escapeHtml(item.unit || '-')}</td>
                        <td class="py-2 pr-4">${escapeHtml(item.item_description || '-')}</td>
                        <td class="py-2 pr-4 text-right">${qty}</td>
                        <td class="py-2 pr-4 text-right">${unitCost}</td>
                        <td class="py-2 pr-4 text-right">${totalCost}</td>
                        <td class="py-2 pr-4 text-center">
                            <div class="inline-flex items-center gap-2">
                                <button
                                    type="button"
                                    class="js-edit-item-btn inline-flex items-center rounded-lg border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs font-medium text-sky-700 hover:bg-sky-100"
                                    data-item-index="${idx}"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="js-delete-item-btn inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700 hover:bg-rose-100"
                                    data-item-id="${item.id}"
                                >
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>`
                );
            });
        }

        function updateItemsCountBadge(prId, count) {
            const badge = $(`.js-items-btn[data-id='${prId}']`);
            if (!badge.length) {
                return;
            }
            badge.text(count);
        }

        function updateMainRowTotal(prId, total) {
            const cell = $(`tr[data-row-id='${prId}'] .js-pr-total`);
            if (!cell.length) {
                return;
            }

            if (total === null || total === '' || Number.isNaN(Number(total))) {
                cell.text('-');
                return;
            }

            cell.text(Number(total).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        }

        function resetItemForm() {
            itemFormMode = 'create';
            $('#itemFormTitle').text('Add New Item');
            $('#resetItemFormBtn').addClass('hidden');
            $('#saveItemBtn').text('Save Item').prop('disabled', false);
            $('#itemFormItemId').val('');
            $('#itemStockNo').val('');
            $('#itemUnit').val('');
            $('#itemDescription').val('');
            $('#itemQty').val('');
            $('#itemUnitCost').val('');
            $('#itemTotalCost').val('');
        }

        function setItemFormForEdit(item) {
            itemFormMode = 'edit';
            $('#itemFormTitle').text(`Edit Item #${item.id}`);
            $('#resetItemFormBtn').removeClass('hidden');
            $('#saveItemBtn').text('Update Item');
            $('#itemFormItemId').val(item.id);
            $('#itemStockNo').val(item.stock_property_no || '');
            $('#itemUnit').val(item.unit || '');
            $('#itemDescription').val(item.item_description || '');
            $('#itemQty').val(item.quantity ?? '');
            $('#itemUnitCost').val(item.unit_cost ?? '');
            $('#itemTotalCost').val(item.total_cost ?? '');
        }

        function loadItems(prId, prNo) {
            currentItemsContext = { prId, prNo };
            currentItems = [];
            $('#itemsModalTitle').text(`PR Items - ${prNo}`);
            $('#itemsModalBody').html('<tr><td colspan="8" class="py-6 text-center text-slate-500">Loading...</td></tr>');

            return $.ajax({
                url: 'items.php',
                method: 'GET',
                dataType: 'json',
                data: { id: prId }
            }).done(function (res) {
                if (!res.ok) {
                    $('#itemsModalBody').html(`<tr><td colspan="8" class="py-6 text-center text-rose-600">${escapeHtml(res.message || 'Failed to load items.')}</td></tr>`);
                    return;
                }
                const items = res.items || [];
                currentItems = items;
                updateItemsCountBadge(prId, items.length);
                renderItems(items);
            }).fail(function () {
                $('#itemsModalBody').html('<tr><td colspan="8" class="py-6 text-center text-rose-600">Failed to load items.</td></tr>');
            });
        }

        $(document).on('click', '.js-items-btn', function () {
            const id = Number($(this).data('id'));
            const pr = String($(this).data('pr') || '');
            resetItemForm();
            openModal('#itemsModal');
            loadItems(id, pr);
        });

        $('#closeItemsModal').on('click', function () {
            closeModal('#itemsModal');
            resetItemForm();
        });

        $(document).on('click', '.js-close-modal', function () {
            const modal = $(this).data('modal');
            if (modal) {
                closeModal(modal);
                if (modal === '#itemsModal') {
                    resetItemForm();
                }
            }
        });

        $(document).on('click', '.js-delete-btn', function () {
            pendingDelete = {
                id: $(this).data('id'),
                pr: $(this).data('pr') || '',
                row: $(this).closest('tr')
            };
            $('#deleteModalText').text(`Delete PR No. ${pendingDelete.pr}? This action cannot be undone.`);
            openModal('#deleteModal');
        });

        $('#cancelDeleteBtn').on('click', function () {
            pendingDelete = null;
            closeModal('#deleteModal');
        });

        $(document).on('click', '.js-delete-item-btn', function () {
            const itemId = Number($(this).data('item-id'));
            if (!itemId || !currentItemsContext.prId) {
                return;
            }

            pendingItemDelete = {
                itemId,
                prId: currentItemsContext.prId,
                prNo: currentItemsContext.prNo
            };

            $('#deleteItemModalText').text(`Delete this item from PR No. ${pendingItemDelete.prNo}? This action cannot be undone.`);
            openModal('#deleteItemModal');
        });

        $(document).on('click', '.js-edit-item-btn', function () {
            const idx = Number($(this).data('item-index'));
            const item = Number.isInteger(idx) ? currentItems[idx] : null;
            if (!item) {
                return;
            }
            setItemFormForEdit(item);
            $('#itemDescription').trigger('focus');
        });

        $('#cancelDeleteItemBtn').on('click', function () {
            pendingItemDelete = null;
            closeModal('#deleteItemModal');
        });

        $('#confirmDeleteItemBtn').on('click', function () {
            if (!pendingItemDelete) {
                closeModal('#deleteItemModal');
                return;
            }

            const btn = $(this);
            btn.prop('disabled', true).text('Deleting...');

            $.ajax({
                url: 'delete_item.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    item_id: pendingItemDelete.itemId,
                    pr_id: pendingItemDelete.prId
                }
            }).done(function (res) {
                if (!res.ok) {
                    showStatus('error', res.message || 'Failed to delete item.');
                    return;
                }

                showStatus('success', res.message || 'Item deleted successfully.');
                closeModal('#deleteItemModal');
                updateMainRowTotal(pendingItemDelete.prId, res.pr_total_cost ?? null);
                loadItems(pendingItemDelete.prId, pendingItemDelete.prNo);
            }).fail(function (xhr) {
                let message = 'Failed to delete item.';
                try {
                    const json = JSON.parse(xhr.responseText);
                    if (json.message) {
                        message = json.message;
                    }
                } catch (_) {}
                showStatus('error', message);
            }).always(function () {
                btn.prop('disabled', false).text('Delete Item');
                pendingItemDelete = null;
            });
        });

        $('#resetItemFormBtn').on('click', function () {
            resetItemForm();
        });

        $('#itemForm').on('submit', function (e) {
            e.preventDefault();

            if (!currentItemsContext.prId) {
                showStatus('error', 'Open a PR items list first.');
                return;
            }

            const itemId = Number($('#itemFormItemId').val() || 0);
            const saveBtn = $('#saveItemBtn');
            saveBtn.prop('disabled', true).text(itemFormMode === 'edit' ? 'Updating...' : 'Saving...');

            $.ajax({
                url: 'save_item.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    pr_id: currentItemsContext.prId,
                    item_id: itemId > 0 ? itemId : '',
                    stock_property_no: $('#itemStockNo').val(),
                    unit: $('#itemUnit').val(),
                    item_description: $('#itemDescription').val(),
                    quantity: $('#itemQty').val(),
                    unit_cost: $('#itemUnitCost').val(),
                    total_cost: $('#itemTotalCost').val()
                }
            }).done(function (res) {
                if (!res.ok) {
                    showStatus('error', res.message || 'Failed to save item.');
                    return;
                }

                showStatus('success', res.message || 'Item saved successfully.');
                resetItemForm();
                updateMainRowTotal(currentItemsContext.prId, res.pr_total_cost ?? null);
                loadItems(currentItemsContext.prId, currentItemsContext.prNo);
            }).fail(function (xhr) {
                let message = 'Failed to save item.';
                try {
                    const json = JSON.parse(xhr.responseText);
                    if (json.message) {
                        message = json.message;
                    }
                } catch (_) {}
                showStatus('error', message);
            }).always(function () {
                saveBtn.prop('disabled', false).text(itemFormMode === 'edit' ? 'Update Item' : 'Save Item');
            });
        });

        $('#confirmDeleteBtn').on('click', function () {
            if (!pendingDelete || !pendingDelete.id) {
                closeModal('#deleteModal');
                return;
            }

            const btn = $(this);
            btn.prop('disabled', true).text('Deleting...');

            $.ajax({
                url: 'delete.php',
                method: 'POST',
                dataType: 'json',
                data: { id: pendingDelete.id }
            }).done(function (res) {
                if (!res.ok) {
                    showStatus('error', res.message || 'Delete failed.');
                    return;
                }

                showStatus('success', res.message || 'Deleted successfully.');
                if (pendingDelete.row) {
                    pendingDelete.row.fadeOut(150, function () {
                        $(this).remove();
                        ensureEmptyState();
                    });
                }
                const recordsCount = $('#recordsCount');
                const currentCount = parseInt(recordsCount.text(), 10) || 0;
                recordsCount.text(Math.max(0, currentCount - 1));
                closeModal('#deleteModal');
            }).fail(function (xhr) {
                let message = 'Delete failed.';
                try {
                    const json = JSON.parse(xhr.responseText);
                    if (json.message) {
                        message = json.message;
                    }
                } catch (_) {}
                showStatus('error', message);
            }).always(function () {
                btn.prop('disabled', false).text('Delete');
                pendingDelete = null;
            });
        });

        $('#uploadForm').on('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(this);
            formData.append('action', 'process');
            $('#submitBtn').prop('disabled', true).text('Processing...');
            showStatus('info', 'Uploading and extracting fields. This can take a few seconds for scanned PDFs.');

            $.ajax({
                url: 'upload.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function (res) {
                if (!res.ok) {
                    showStatus('error', res.message || 'Failed to process PDF.');
                    return;
                }

                pendingProcess = {
                    temp_file: res.temp_file || null,
                    items_count: Number(res.items_count || 0),
                    data: res.data || {}
                };

                $('#processConfirmText').text(`Found ${pendingProcess.items_count} item(s). Continue saving to database?`);
                renderResult(pendingProcess.data);
                showStatus('info', `Found ${pendingProcess.items_count} item(s). Choose Continue to save or Cancel to discard.`);
                openModal('#processConfirmModal');
            }).fail(function (xhr) {
                let message = 'Unexpected error while processing the file.';
                let diagnostics = '';
                try {
                    const json = JSON.parse(xhr.responseText);
                    if (json.message) {
                        message = json.message;
                    }
                    if (Array.isArray(json.diagnostics) && json.diagnostics.length > 0) {
                        diagnostics = '\n\nDiagnostics:\n' + json.diagnostics.map((d) => {
                            const cmd = d.command || '';
                            const code = d.exit_code ?? '';
                            const out = (d.output || '').toString().slice(0, 240);
                            return `- [${code}] ${cmd} :: ${out}`;
                        }).join('\n');
                    }
                } catch (_) {}

                showStatus('error', message + diagnostics);
            }).always(function () {
                $('#submitBtn').prop('disabled', false).text('Process & Save');
            });
        });

        function cancelPendingProcess() {
            if (!pendingProcess || !pendingProcess.temp_file) {
                return $.Deferred().resolve().promise();
            }

            return $.ajax({
                url: 'upload.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'cancel',
                    temp_file: pendingProcess.temp_file
                }
            });
        }

        $('#cancelProcessBtn').on('click', function () {
            const btn = $(this);
            btn.prop('disabled', true).text('Canceling...');

            cancelPendingProcess().always(function () {
                pendingProcess = null;
                btn.prop('disabled', false).text('Cancel');
                closeModal('#processConfirmModal');
                showStatus('info', 'Processing canceled. Entry was not saved.');
            });
        });

        $('#continueProcessBtn').on('click', function () {
            if (!pendingProcess || !pendingProcess.temp_file) {
                closeModal('#processConfirmModal');
                showStatus('error', 'No processed file found. Please upload again.');
                return;
            }

            const btn = $(this);
            btn.prop('disabled', true).text('Saving...');

            $.ajax({
                url: 'upload.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'save',
                    temp_file: pendingProcess.temp_file
                }
            }).done(function (res) {
                if (!res.ok) {
                    showStatus('error', res.message || 'Failed to save entry.');
                    return;
                }

                showStatus('success', `Saved successfully. Record ID: ${res.record_id}`);
                renderResult(res.data || {});
                pendingProcess = null;
                closeModal('#processConfirmModal');
                setTimeout(() => window.location.reload(), 1000);
            }).fail(function (xhr) {
                let message = 'Failed to save entry.';
                try {
                    const json = JSON.parse(xhr.responseText);
                    if (json.message) {
                        message = json.message;
                    }
                } catch (_) {}

                showStatus('error', message);
            }).always(function () {
                btn.prop('disabled', false).text('Continue');
            });
        });
    </script>
</body>
</html>
