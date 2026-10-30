// Exercise the real repeater handlers with a minimal jQuery fixture.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const rows = [0, 1, 2].map(makeRow);
const handlers = {};
function makeRow(index) {
  return { index, names: ['label', 'id', 'color'].map(field => `order_overview_status_settings[statuses][${index}][${field}]`) };
}
function collection(items = []) {
  return {
    length: items.length,
    each(callback) { items.forEach((item, index) => callback.call(item, index, item)); return this; },
    find() { return collection(); }
  };
}
function wrapRow(row) {
  return {
    row, length: 1,
    find() { return collection(); },
    closest() { return this; },
    remove() { rows.splice(rows.indexOf(row), 1); }
  };
}
const rowContainer = {
  length: 1,
  find(selector) {
    if (selector === '[name]') return collection(rows.flatMap(row => row.names.map(name => ({ name }))));
    if (selector === '.hb-ucs-order-overview-status-row') return collection(rows);
    return collection();
  },
  on(event, selector, callback) { handlers[selector] = callback; },
  append(markup) { rows.push(markup.row); }
};
const template = { length: 1, html() { return '<tr><input name="order_overview_status_settings[statuses][__index__][label]"></tr>'; } };
const addButton = { length: 1, on(event, callback) { handlers.add = callback; } };
function $(value) {
  if (typeof value === 'function') return value($);
  if (value === '#hb-ucs-order-overview-status-rows') return rowContainer;
  if (value === '#tmpl-hb-ucs-order-overview-status-row') return template;
  if (value === '#hb-ucs-add-order-overview-status') return addButton;
  if (value && typeof value === 'object' && 'index' in value) return wrapRow(value);
  if (typeof value === 'string' && value.startsWith('<tr>')) {
    return wrapRow(makeRow(Number(value.match(/\[statuses\]\[(\d+)\]/)[1])));
  }
  return collection();
}
$.fn = {};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../../src/Modules/OrderOverviewStatus/assets/admin-order-overview-status.js'), 'utf8'), { jQuery: $, window: {} });
handlers['.hb-ucs-remove-order-overview-status'].call(rows[1]);
handlers.add();
assert.deepEqual(rows.map(row => row.index), [0, 2, 3]);
handlers['.hb-ucs-remove-order-overview-status'].call(rows[2]);
handlers.add();
rows.reverse();
handlers.add();
const names = rows.flatMap(row => row.names);
assert.equal(new Set(names).size, names.length, 'All field names remain unique after deleting, adding and sorting');
assert.deepEqual(rows.map(row => row.index), [4, 2, 0, 5]);
console.log('PASS A10: unique status fields after deleting, adding and sorting');
