// Admin settings for Link Gate.
//
// The four settings at the top are plain typed entries, which the auto-built
// extension page renders on both release lines without a custom component.
//
// The per-language editor below them has to be a callback setting instead,
// because the set of languages is whatever the forum has installed and a typed
// entry cannot describe that. Both majors invoke a function entry with `this`
// set to the extension page, whose setting() streams feed the regular Save
// button, so it saves with everything else rather than needing its own button.
//
import app from 'flarum/admin/app';

// Mithril is the global Flarum exposes, and core's own JSX compiles to these
// same m(...) calls. Deliberately not imported: flarum-webpack-config does not
// externalize mithril, so an import would bundle a second copy of it.
const m = window.m;

const EXT_ID = 'linkrobins-link-gate';
const PREFIX = EXT_ID + '.';
const TRANSLATIONS = PREFIX + 'translations';

const trans = (key) => app.translator.trans(EXT_ID + '.admin.' + key);

function settings() {
  return [
    {
      setting: PREFIX + 'enabled',
      label: trans('settings.enabled_label'),
      help: trans('settings.enabled_help'),
      type: 'boolean',
      default: true,
    },
    {
      setting: PREFIX + 'domains',
      label: trans('settings.domains_label'),
      help: trans('settings.domains_help'),
      type: 'textarea',
    },
    {
      setting: PREFIX + 'html',
      label: trans('settings.html_label'),
      help: trans('settings.html_help'),
      type: 'textarea',
    },
    {
      setting: PREFIX + 'fallback',
      label: trans('settings.fallback_label'),
      help: trans('settings.fallback_help'),
      type: 'text',
    },
  ];
}

// The languages this forum has installed, as [code, name] pairs. Falls back to
// an empty list rather than throwing, so a payload without locales just means
// the editor does not draw.
function locales() {
  const all = (app.data && app.data.locales) || {};

  return Object.keys(all)
    .sort()
    .map((code) => [code, all[code]]);
}

function read(page) {
  const raw = page.setting(TRANSLATIONS)() || '';

  if (String(raw).trim() === '') return {};

  try {
    const decoded = JSON.parse(raw);
    return decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : {};
  } catch (e) {
    // Unreadable JSON is treated as "nothing written yet" rather than thrown
    // away, since the admin still has to be able to save over it.
    return {};
  }
}

function write(page, code, field, value) {
  const all = read(page);
  const entry = Object.assign({ html: '', text: '' }, all[code]);

  entry[field] = value;

  // A language the admin has emptied is removed outright, so the stored value
  // does not accumulate blank objects for every language they ever opened.
  if (!String(entry.html).trim() && !String(entry.text).trim()) {
    delete all[code];
  } else {
    all[code] = entry;
  }

  page.setting(TRANSLATIONS)(Object.keys(all).length ? JSON.stringify(all) : '');
}

function field(page, code, name, label, rows) {
  const all = read(page);
  const value = (all[code] && all[code][name]) || '';

  return m('.Form-group', [
    m('label', label),
    m('textarea.FormControl', {
      rows,
      value,
      oninput: (e) => write(page, code, name, e.target.value),
    }),
  ]);
}

// `this` is the extension page, which owns the setting() streams.
function translationEditor() {
  const page = this;
  const list = locales();

  // One language installed means there is nothing to translate into, so the
  // editor stays out of the way rather than showing a single redundant box.
  if (list.length < 2) return null;

  return m('.Form-group', [
    m('label', trans('translations.label')),
    m('.helpText', trans('translations.help')),
    list.map(([code, name]) => {
      const all = read(page);
      const written = !!all[code];

      return m('details.LinkGate-translation', { key: code, open: written }, [
        m('summary', name + ' (' + code + ')'),
        field(page, code, 'html', trans('settings.html_label'), 4),
        field(page, code, 'text', trans('settings.fallback_label'), 2),
      ]);
    }),
  ]);
}

app.initializers.add(EXT_ID, () => {
  const registry = app.registry.for(EXT_ID);

  // Resolved here rather than at module load, so the labels come back in the
  // admin's own language instead of frozen to the English fallback.
  settings().forEach((setting, index) => registry.registerSetting(setting, 100 - index));

  registry.registerSetting(translationEditor, 50);

  registry.registerPermission(
    {
      icon: 'fas fa-user-lock',
      label: trans('permissions.view_gated_links_label'),
      permission: PREFIX + 'viewGatedLinks',
    },
    'view'
  );
});
