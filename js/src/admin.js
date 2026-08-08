// Admin settings for Link Gate.
//
// Every entry is a plain typed setting, which the auto-built extension page
// renders without a custom component.
//
import app from 'flarum/admin/app';

const EXT_ID = 'linkrobins-link-gate';
const PREFIX = EXT_ID + '.';

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

app.initializers.add(EXT_ID, () => {
  // 1.8 calls this extensionData; 2.x renamed it to registry. Same API.
  const registry = app.extensionData.for(EXT_ID);

  // Resolved here rather than at module load, so the labels come back in the
  // admin's own language instead of frozen to the English fallback.
  settings().forEach((setting, index) => registry.registerSetting(setting, 100 - index));

  registry.registerPermission(
    {
      icon: 'fas fa-user-lock',
      label: trans('permissions.view_gated_links_label'),
      permission: PREFIX + 'viewGatedLinks',
    },
    'view'
  );
});
