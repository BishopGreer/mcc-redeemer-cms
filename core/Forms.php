<?php
/**
 * Forms — custom form engine for OurSaintFrancis CMS.
 *
 * Handles Forminator / Contact Form 7 import, HTML rendering,
 * server-side validation, hCaptcha verification, file uploads,
 * submission storage, and display formatting.
 */
class Forms
{
    // -----------------------------------------------------------------------
    // Import
    // -----------------------------------------------------------------------

    /**
     * Import a Forminator JSON export string.
     * Returns ['form_id' => N] on success, or ['error' => '...'] on failure.
     */
    public static function importForminator(string $json, int $siteId): array
    {
        // Strip BOM and non-printable leading bytes
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);
        $json = ltrim($json);

        $decoded = json_decode($json, true);

        if ($decoded === null) {
            $errMsg = function_exists('json_last_error_msg') ? json_last_error_msg() : 'unknown';
            return ['error' => 'Could not parse JSON: ' . $errMsg . '. Make sure you uploaded the complete .txt export file.'];
        }

        // Support multiple export wrapper formats:
        // Format A: {"type":"form","data":{"fields":[...],"settings":{...}}}
        // Format B: {"fields":[...],"settings":{...}}  (data unwrapped)
        // Format C: {"data":{"fields":[...]}}
        $dataRoot = null;
        if (isset($decoded['data']['fields'])) {
            $dataRoot = $decoded['data'];
        } elseif (isset($decoded['fields'])) {
            $dataRoot = $decoded;
        } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
            $dataRoot = $decoded['data'];
        }

        if (!$dataRoot || !isset($dataRoot['fields']) || !is_array($dataRoot['fields'])) {
            return ['error' => 'JSON parsed but no "fields" array found. This does not appear to be a Forminator form export.'];
        }

        $settings  = $dataRoot['settings'] ?? [];
        $rawFields = $dataRoot['fields'];
        $notifs    = $dataRoot['notifications'] ?? [];

        $title = $settings['formName'] ?? 'Imported Form';
        $slug  = self::makeSlug($title, $siteId);

        // Extract admin notification e-mail
        $notifyEmail = null;
        foreach ($notifs as $n) {
            if (($n['type'] ?? '') === 'default') {
                $notifyEmail = $n['recipients'] ?? null;
                break;
            }
        }

        // Normalise fields
        $fields = [];
        foreach ($rawFields as $f) {
            $type = $f['type'] ?? '';

            // Skip Forminator auto-value hidden fields (submission_id, date, IP…)
            if ($type === 'hidden') {
                continue;
            }

            $fields[] = [
                'id'             => $f['id'] ?? ($f['element_id'] ?? uniqid('f')),
                'type'           => $type,
                'label'          => $f['field_label'] ?? '',
                'placeholder'    => $f['placeholder'] ?? '',
                'required'       => self::isBool($f['required'] ?? '') || self::isBool($f['fname_required'] ?? ''),
                'options'        => array_map(fn($o) => ['label' => $o['label'], 'value' => $o['value']], $f['options'] ?? []),
                'conditions'     => $f['conditions'] ?? [],
                'condition_rule' => $f['condition_rule'] ?? 'all',
                'parent_group'   => $f['parent_group'] ?? '',
                'is_repeater'    => ($f['is_repeater'] ?? 'false') === 'true',
                'config'         => self::extractConfig($f),
            ];
        }

        $formId = Database::insert('custom_forms', [
            'site_id'        => $siteId,
            'title'          => $title,
            'slug'           => $slug,
            'description'    => '',
            'status'         => 'draft',
            'requires_login' => 0,
            'use_hcaptcha'   => 1,
            'notify_email'   => $notifyEmail,
            'success_msg'    => 'Thank you — your form has been submitted successfully.',
            'fields_json'    => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'imported_from'  => 'forminator',
        ]);

        return ['form_id' => $formId];
    }

    /**
     * Import Contact Form 7 form markup (the content inside [contact-form-7 … form="…"]).
     * Paste only the inner tag body. Returns ['form_id' => N] or ['error' => '...'].
     */
    public static function importCF7(string $markup, string $title, int $siteId): array
    {
        $fields = [];
        $seq    = 0;

        preg_match_all('/\[([^\]]+)\]/', $markup, $m);
        foreach ($m[1] as $raw) {
            $parts   = preg_split('/\s+/', trim($raw));
            $tag     = array_shift($parts) ?? '';
            $req     = str_ends_with($tag, '*');
            $baseTag = rtrim($tag, '*');

            $type = match ($baseTag) {
                'text'     => 'text',
                'email'    => 'email',
                'tel'      => 'phone',
                'number'   => 'number',
                'textarea' => 'textarea',
                'date'     => 'date',
                'checkbox' => 'checkbox',
                'radio'    => 'radio',
                'file'     => 'upload',
                'select'   => 'radio',
                default    => null,
            };
            if (!$type) {
                continue;
            }

            // First bare word = field slug; quoted strings = label or option
            $fieldSlug = null;
            $label     = '';
            $options   = [];
            foreach ($parts as $p) {
                if (!$fieldSlug && preg_match('/^[a-z][a-z0-9_-]*$/i', $p)) {
                    $fieldSlug = $p;
                } elseif (preg_match('/^"(.+)"$/', $p, $lm)) {
                    if (!$label) {
                        $label = $lm[1];
                    } else {
                        // Subsequent quoted strings are options for select/checkbox/radio
                        $options[] = ['label' => $lm[1], 'value' => slugify($lm[1])];
                    }
                }
            }
            if (!$fieldSlug) {
                continue;
            }

            $seq++;
            $fields[] = [
                'id'             => $type . '-' . $seq,
                'type'           => $type,
                'label'          => $label ?: ucwords(str_replace(['-', '_'], ' ', $fieldSlug)),
                'placeholder'    => '',
                'required'       => $req,
                'options'        => $options,
                'conditions'     => [],
                'condition_rule' => 'all',
                'parent_group'   => '',
                'is_repeater'    => false,
                'config'         => [],
            ];
        }

        if (empty($fields)) {
            return ['error' => 'No recognisable CF7 field tags found. Paste only the form field markup.'];
        }

        $slug = self::makeSlug($title, $siteId);

        $formId = Database::insert('custom_forms', [
            'site_id'        => $siteId,
            'title'          => $title,
            'slug'           => $slug,
            'description'    => '',
            'status'         => 'draft',
            'requires_login' => 0,
            'use_hcaptcha'   => 1,
            'notify_email'   => null,
            'success_msg'    => 'Thank you — your form has been submitted successfully.',
            'fields_json'    => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'imported_from'  => 'cf7',
        ]);

        return ['form_id' => $formId];
    }

    // -----------------------------------------------------------------------
    // Rendering (public form HTML)
    // -----------------------------------------------------------------------

    /**
     * Render the complete <form> element for a form row from the database.
     *
     * @param array  $form   Row from custom_forms
     * @param array  $values Previously submitted values (for re-display on error)
     * @param array  $errors Validation errors keyed by field id
     */
    public static function renderForm(array $form, array $values = [], array $errors = []): string
    {
        $fields   = json_decode($form['fields_json'] ?? '[]', true) ?: [];
        $siteKey  = setting('hcaptcha_site_key');
        $useHcap  = $form['use_hcaptcha'] && $siteKey;
        $nonce    = cspNonce();

        // Build parent→children map
        $byParent = ['__root__' => []];
        foreach ($fields as $f) {
            $parent = $f['parent_group'] ?: '__root__';
            $byParent[$parent][] = $f;
        }

        $action = siteUrl('forms/' . $form['slug']);

        $html  = '<form method="post" action="' . h($action) . '" class="custom-form" enctype="multipart/form-data" novalidate>';
        $html .= '<input type="hidden" name="_csrf" value="' . h(Auth::csrf()) . '">';
        $html .= '<input type="hidden" name="_form_id" value="' . (int)$form['id'] . '">';

        // Global error banner
        if (!empty($errors['_global'])) {
            $html .= '<div class="form-alert form-alert-error">' . h($errors['_global']) . '</div>';
        }
        if (!empty($errors['_hcaptcha'])) {
            $html .= '<div class="form-alert form-alert-error">' . h($errors['_hcaptcha']) . '</div>';
        }

        foreach ($byParent['__root__'] as $field) {
            $html .= self::renderField($field, $byParent, $values, $errors);
        }

        if ($useHcap) {
            $html .= '<div class="form-group form-captcha">';
            $html .= '<div class="h-captcha" data-sitekey="' . h($siteKey) . '"></div>';
            if (!empty($errors['_hcaptcha'])) {
                $html .= '<span class="form-error">' . h($errors['_hcaptcha']) . '</span>';
            }
            $html .= '</div>';
        }

        $html .= '<div class="form-group form-submit">';
        $html .= '<button type="submit" class="btn btn-primary btn-lg">Submit</button>';
        $html .= '</div>';
        $html .= '</form>';

        // Conditional JS
        $html .= self::conditionalJs($nonce);

        return $html;
    }

    private static function renderField(array $f, array $byParent, array $values, array $errors): string
    {
        $id   = $f['id'];
        $type = $f['type'];
        $err  = $errors[$id] ?? null;

        // Condition wrapper attributes
        $condAttr    = '';
        $hiddenStyle = '';
        if (!empty($f['conditions'])) {
            $condAttr    = ' data-conditions=\'' . json_encode($f['conditions'], JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) . '\'';
            $condAttr   .= ' data-condition-rule="' . h($f['condition_rule'] ?? 'all') . '"';
            $hiddenStyle = ' style="display:none"';
        }

        $open  = '<div class="ffwrap" id="ffw-' . h($id) . '"' . $condAttr . $hiddenStyle . '>';
        $close = '</div>';

        if ($type === 'group') {
            $inner  = '<fieldset class="form-section" id="fwg-' . h($id) . '">';
            if ($f['label']) {
                $inner .= '<legend class="form-section-legend">' . h($f['label']) . '</legend>';
            }
            foreach ($byParent[$id] ?? [] as $child) {
                $inner .= self::renderField($child, $byParent, $values, $errors);
            }
            $inner .= '</fieldset>';
            return $inner; // Groups don't get the condition wrapper — their children do
        }

        $inner = match (true) {
            $type === 'name'     => self::rName($f, $values[$id] ?? [], $err),
            $type === 'address'  => self::rAddress($f, $values[$id] ?? [], $err),
            $type === 'upload'   => self::rUpload($f, $err),
            $type === 'radio'    => self::rRadio($f, $values[$id] ?? null, $err),
            $type === 'checkbox' => self::rCheckbox($f, $values[$id] ?? [], $err),
            $type === 'textarea' => self::rTextarea($f, $values[$id] ?? '', $err),
            $type === 'date'     => self::rDate($f, $values[$id] ?? '', $err),
            default              => self::rInput($f, $values[$id] ?? '', $err),
        };

        return $open . $inner . $close;
    }

    // -- individual renderers ------------------------------------------------

    private static function rInput(array $f, string $value, ?string $err): string
    {
        $id   = $f['id'];
        $req  = !empty($f['required']);
        $type = match ($f['type']) { 'email' => 'email', 'phone' => 'tel', 'number' => 'number', default => 'text' };
        $cfg  = $f['config'] ?? [];
        $min  = isset($cfg['min']) && $cfg['min'] !== '' ? ' min="' . h($cfg['min']) . '"' : '';
        $max  = isset($cfg['max']) && $cfg['max'] !== '' ? ' max="' . h($cfg['max']) . '"' : '';

        $h  = self::fg($err);
        $h .= self::lbl('ff_' . $id, $f['label'] ?? '', $req);
        $h .= '<input type="' . $type . '" id="ff_' . h($id) . '" name="ff[' . h($id) . ']"'
            . ' value="' . h($value) . '" placeholder="' . h($f['placeholder'] ?? '') . '"'
            . $min . $max . ($req ? ' required' : '') . '>';
        if ($err) $h .= self::errmsg($err);
        $h .= '</div>';
        return $h;
    }

    private static function rTextarea(array $f, string $value, ?string $err): string
    {
        $id  = $f['id'];
        $req = !empty($f['required']);
        $h   = self::fg($err);
        $h  .= self::lbl('ff_' . $id, $f['label'] ?? '', $req);
        $h  .= '<textarea id="ff_' . h($id) . '" name="ff[' . h($id) . ']" rows="5"'
             . ' placeholder="' . h($f['placeholder'] ?? '') . '"'
             . ($req ? ' required' : '') . '>' . h($value) . '</textarea>';
        if ($err) $h .= self::errmsg($err);
        $h .= '</div>';
        return $h;
    }

    private static function rDate(array $f, string $value, ?string $err): string
    {
        $id  = $f['id'];
        $req = !empty($f['required']);
        $h   = self::fg($err);
        $h  .= self::lbl('ff_' . $id, $f['label'] ?? '', $req);
        $h  .= '<input type="date" id="ff_' . h($id) . '" name="ff[' . h($id) . ']"'
             . ' value="' . h($value) . '"' . ($req ? ' required' : '') . '>';
        if ($err) $h .= self::errmsg($err);
        $h .= '</div>';
        return $h;
    }

    private static function rRadio(array $f, ?string $value, ?string $err): string
    {
        $id  = $f['id'];
        $req = !empty($f['required']);
        $h   = '<div class="form-group form-group-radio' . ($err ? ' has-error' : '') . '">';
        $h  .= '<p class="form-label">' . h($f['label'] ?? '') . ($req ? ' <span class="req">*</span>' : '') . '</p>';
        $h  .= '<div class="radio-list">';
        foreach ($f['options'] as $opt) {
            $chk = ($value === $opt['value']) ? ' checked' : '';
            $h  .= '<label class="radio-opt"><input type="radio" name="ff[' . h($id) . ']"'
                 . ' value="' . h($opt['value']) . '"' . $chk . ($req ? ' required' : '') . '> '
                 . h($opt['label']) . '</label>';
        }
        $h .= '</div>';
        if ($err) $h .= self::errmsg($err);
        $h .= '</div>';
        return $h;
    }

    private static function rCheckbox(array $f, array $values, ?string $err): string
    {
        $id  = $f['id'];
        $req = !empty($f['required']);
        $h   = '<div class="form-group form-group-checkbox' . ($err ? ' has-error' : '') . '">';
        $h  .= '<p class="form-label">' . h($f['label'] ?? '') . ($req ? ' <span class="req">*</span>' : '') . '</p>';
        $h  .= '<div class="checkbox-list">';
        foreach ($f['options'] as $opt) {
            $chk = in_array($opt['value'], $values) ? ' checked' : '';
            $h  .= '<label class="checkbox-opt"><input type="checkbox" name="ff[' . h($id) . '][]"'
                 . ' value="' . h($opt['value']) . '"' . $chk . '> ' . h($opt['label']) . '</label>';
        }
        $h .= '</div>';
        if ($err) $h .= self::errmsg($err);
        $h .= '</div>';
        return $h;
    }

    private static function rName(array $f, array $values, ?string $err): string
    {
        $id  = $f['id'];
        $cfg = $f['config'] ?? [];
        $req = !empty($f['required']);
        $h   = '<div class="form-compound' . ($err ? ' has-error' : '') . '">';
        $h  .= '<p class="form-label compound-label">' . h($f['label'] ?? 'Name') . ($req ? ' <span class="req">*</span>' : '') . '</p>';
        $h  .= '<div class="name-row">';

        if (!empty($cfg['prefix'])) {
            $h .= '<div class="np np-prefix">';
            $h .= '<label>' . h($cfg['prefix_label'] ?? 'Prefix') . '</label>';
            $h .= '<select name="ff[' . h($id) . '][prefix]"><option value="">--</option>';
            foreach (['Rev.', 'Fr.', 'Dcn.', 'Br.', 'Sr.', 'Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Most Rev.', 'Rt. Rev.'] as $p) {
                $sel = ($values['prefix'] ?? '') === $p ? ' selected' : '';
                $h  .= '<option' . $sel . '>' . h($p) . '</option>';
            }
            $h .= '</select></div>';
        }

        foreach ([
            'fname' => ['fname_label' => 'First Name', 'fname_placeholder' => '', 'fname_required' => false],
            'mname' => ['mname_label' => 'Middle Name', 'mname_placeholder' => '', 'mname_required' => false],
            'lname' => ['lname_label' => 'Last Name', 'lname_placeholder' => '', 'lname_required' => false],
        ] as $part => $defaults) {
            if (empty($cfg[$part])) {
                continue;
            }
            $lblKey  = $part . '_label';
            $phKey   = $part . '_placeholder';
            $reqKey  = $part . '_required';
            $partReq = !empty($cfg[$reqKey]);
            $h .= '<div class="np np-' . $part . '">';
            $h .= '<label>' . h($cfg[$lblKey] ?? $defaults[$lblKey]) . ($partReq ? ' <span class="req">*</span>' : '') . '</label>';
            $h .= '<input type="text" name="ff[' . h($id) . '][' . $part . ']"'
                . ' value="' . h($values[$part] ?? '') . '"'
                . ' placeholder="' . h($cfg[$phKey] ?? '') . '"'
                . ($partReq ? ' required' : '') . '>';
            $h .= '</div>';
        }

        $h .= '</div>';
        if ($err) $h .= self::errmsg($err);
        $h .= '</div>';
        return $h;
    }

    private static function rAddress(array $f, array $values, ?string $err): string
    {
        $id  = $f['id'];
        $cfg = $f['config'] ?? [];
        $req = !empty($f['required']);
        $h   = '<div class="form-compound' . ($err ? ' has-error' : '') . '">';
        $h  .= '<p class="form-label compound-label">' . h($f['label'] ?? 'Address') . ($req ? ' <span class="req">*</span>' : '') . '</p>';

        if (!empty($cfg['street'])) {
            $sr  = !empty($cfg['street_required']);
            $h  .= '<div class="form-group">';
            $h  .= '<label>Street Address' . ($sr ? ' <span class="req">*</span>' : '') . '</label>';
            $h  .= '<input type="text" name="ff[' . h($id) . '][street]" value="' . h($values['street'] ?? '') . '" placeholder="Street address"' . ($sr ? ' required' : '') . '>';
            $h  .= '</div>';
        }
        if (!empty($cfg['line2'])) {
            $h  .= '<div class="form-group">';
            $h  .= '<label>Apartment, suite, etc.</label>';
            $h  .= '<input type="text" name="ff[' . h($id) . '][line2]" value="' . h($values['line2'] ?? '') . '" placeholder="Apt, suite, unit, etc.">';
            $h  .= '</div>';
        }

        $h .= '<div class="address-row">';
        foreach ([
            'city'  => ['label' => 'City',             'req_key' => 'city_required'],
            'state' => ['label' => 'State/Province',    'req_key' => 'state_required'],
            'zip'   => ['label' => 'ZIP / Postal Code', 'req_key' => 'zip_required'],
        ] as $part => $info) {
            if (empty($cfg[$part])) {
                continue;
            }
            $pr  = !empty($cfg[$info['req_key']]);
            $h  .= '<div class="ap ap-' . $part . '">';
            $h  .= '<label>' . $info['label'] . ($pr ? ' <span class="req">*</span>' : '') . '</label>';
            $h  .= '<input type="text" name="ff[' . h($id) . '][' . $part . ']" value="' . h($values[$part] ?? '') . '"' . ($pr ? ' required' : '') . '>';
            $h  .= '</div>';
        }
        $h .= '</div>';

        if (!empty($cfg['country'])) {
            $cr  = !empty($cfg['country_required']);
            $h  .= '<div class="form-group">';
            $h  .= '<label>Country' . ($cr ? ' <span class="req">*</span>' : '') . '</label>';
            $h  .= '<input type="text" name="ff[' . h($id) . '][country]" value="' . h($values['country'] ?? 'United States') . '"' . ($cr ? ' required' : '') . '>';
            $h  .= '</div>';
        }

        if ($err) $h .= self::errmsg($err);
        $h .= '</div>';
        return $h;
    }

    private static function rUpload(array $f, ?string $err): string
    {
        $id    = $f['id'];
        $cfg   = $f['config'] ?? [];
        $multi = !empty($cfg['multiple']);
        $maxMb = $cfg['max_mb'] ?? 8;
        $req   = !empty($f['required']);
        $h     = self::fg($err);
        $h    .= '<label for="fu_' . h($id) . '">' . h($f['label'] ?? 'Upload File') . ($req ? ' <span class="req">*</span>' : '') . '</label>';
        $h    .= '<input type="file" id="fu_' . h($id) . '" name="fu[' . h($id) . ']' . ($multi ? '[]' : '') . '"'
               . ($multi ? ' multiple' : '') . ($req ? ' required' : '') . '>';
        $h    .= '<small class="form-hint">Max ' . $maxMb . ' MB per file.</small>';
        if ($err) $h .= self::errmsg($err);
        $h    .= '</div>';
        return $h;
    }

    // -- small helpers -------------------------------------------------------

    private static function fg(?string $err): string
    {
        return '<div class="form-group' . ($err ? ' has-error' : '') . '">';
    }

    private static function lbl(string $forId, string $text, bool $req): string
    {
        return '<label for="' . h($forId) . '">' . h($text) . ($req ? ' <span class="req">*</span>' : '') . '</label>';
    }

    private static function errmsg(string $msg): string
    {
        return '<span class="form-error">' . h($msg) . '</span>';
    }

    private static function conditionalJs(string $nonce): string
    {
        return <<<JS
<script nonce="{$nonce}">
(function () {
  function getVals(id) {
    var radios = document.querySelectorAll('[name="ff[' + id + ']"]');
    if (radios.length && radios[0].type === 'radio') {
      var ch = document.querySelector('[name="ff[' + id + ']"]:checked');
      return ch ? [ch.value] : [];
    }
    var cbs = document.querySelectorAll('[name="ff[' + id + '][]"]:checked');
    var cbArr = Array.prototype.slice.call(cbs);
    if (document.querySelector('[name="ff[' + id + '][]"]')) {
      return cbArr.map(function (c) { return c.value; });
    }
    var el = document.querySelector('[name="ff[' + id + ']"]');
    return el ? [el.value] : [];
  }
  function check() {
    document.querySelectorAll('[data-conditions]').forEach(function (wrap) {
      var conds = JSON.parse(wrap.dataset.conditions);
      var rule  = (wrap.dataset.conditionRule || 'all').toLowerCase();
      var res   = conds.map(function (c) {
        var fid = c.element_id || c.id || '';
        return getVals(fid).indexOf(c.value) !== -1;
      });
      var show  = rule === 'any' ? res.some(Boolean) : res.every(Boolean);
      wrap.style.display = show ? '' : 'none';
      wrap.querySelectorAll('input,select,textarea').forEach(function (el) {
        if (!show) {
          if (el.required) { el.dataset.req = '1'; el.required = false; }
        } else {
          if (el.dataset.req) { el.required = true; delete el.dataset.req; }
        }
      });
    });
  }
  document.addEventListener('change', check);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', check);
  } else {
    check();
  }
})();
</script>
JS;
    }

    // -----------------------------------------------------------------------
    // Submission processing
    // -----------------------------------------------------------------------

    /**
     * Process a form POST submission.
     * Call inside the public /forms/{slug} route after loading the form row.
     *
     * Returns:
     *   ['success' => true, 'submission_id' => N]
     *   ['errors'  => ['field_id' => 'message', ...]]
     */
    public static function processSubmission(array $form): array
    {
        // CSRF
        Auth::verifyCsrf();

        // hCaptcha
        if ($form['use_hcaptcha'] && setting('hcaptcha_site_key')) {
            $secret   = setting('hcaptcha_secret');
            $response = $_POST['h-captcha-response'] ?? '';
            if (!$response) {
                return ['errors' => ['_hcaptcha' => 'Please complete the CAPTCHA.']];
            }
            $ctx  = stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query(['secret' => $secret, 'response' => $response, 'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '']),
                'timeout' => 10,
            ]]);
            $raw  = @file_get_contents('https://hcaptcha.com/siteverify', false, $ctx);
            $json = $raw ? json_decode($raw, true) : null;
            if (!($json['success'] ?? false)) {
                return ['errors' => ['_hcaptcha' => 'CAPTCHA verification failed. Please try again.']];
            }
        }

        $fields   = json_decode($form['fields_json'] ?? '[]', true) ?: [];
        $rawPost  = $_POST['ff'] ?? [];
        $rawFiles = $_FILES['fu'] ?? [];

        // Flat values map for condition evaluation
        $values = [];
        foreach ($fields as $f) {
            if ($f['type'] === 'group') {
                continue;
            }
            $fid = $f['id'];
            if (array_key_exists($fid, $rawPost)) {
                $values[$fid] = $rawPost[$fid];
            }
        }

        // Validate active fields
        $errors = [];
        $data   = [];

        foreach ($fields as $f) {
            if ($f['type'] === 'group') {
                continue;
            }
            $fid = $f['id'];

            // Skip fields whose conditions are not met
            if (!empty($f['conditions']) && !self::evalConditions($f['conditions'], $f['condition_rule'] ?? 'all', $values)) {
                continue;
            }

            $value    = $values[$fid] ?? null;
            $errMsg   = self::validateField($f, $value);
            if ($errMsg) {
                $errors[$fid] = $errMsg;
            }
            $data[$fid] = $value;
        }

        if (!empty($errors)) {
            return ['errors' => $errors];
        }

        // Save submission
        $siteId = Database::siteId();
        $submissionId = Database::insert('form_submissions', [
            'form_id'      => (int) $form['id'],
            'site_id'      => $siteId,
            'data_json'    => json_encode($data, JSON_UNESCAPED_UNICODE),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'is_read'      => 0,
        ]);

        // File uploads
        if (!empty($rawFiles)) {
            $uploadDir = BASE_PATH . '/public/uploads/form-files/' . $submissionId . '/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            foreach ($rawFiles as $fieldId => $fi) {
                $names    = is_array($fi['name'])     ? $fi['name']     : [$fi['name']];
                $tmps     = is_array($fi['tmp_name']) ? $fi['tmp_name'] : [$fi['tmp_name']];
                $errs     = is_array($fi['error'])    ? $fi['error']    : [$fi['error']];
                $sizes    = is_array($fi['size'])     ? $fi['size']     : [$fi['size']];
                $types    = is_array($fi['type'])     ? $fi['type']     : [$fi['type']];

                for ($i = 0, $n = count($names); $i < $n; $i++) {
                    if ($errs[$i] !== UPLOAD_ERR_OK || !$names[$i]) {
                        continue;
                    }
                    $ext  = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
                    $safe = bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
                    if (move_uploaded_file($tmps[$i], $uploadDir . $safe)) {
                        Database::insert('form_files', [
                            'submission_id' => $submissionId,
                            'field_id'      => $fieldId,
                            'original_name' => $names[$i],
                            'stored_name'   => $safe,
                            'mime_type'     => $types[$i] ?? null,
                            'file_size'     => $sizes[$i] ?? null,
                        ]);
                    }
                }
            }
        }

        // Notify
        if ($form['notify_email']) {
            self::sendNotification($form, $fields, $data, $submissionId);
        }

        return ['success' => true, 'submission_id' => $submissionId];
    }

    // -----------------------------------------------------------------------
    // Condition evaluation
    // -----------------------------------------------------------------------

    public static function evalConditions(array $conditions, string $rule, array $values): bool
    {
        if (empty($conditions)) {
            return true;
        }

        $results = array_map(function ($c) use ($values) {
            $targetId = $c['element_id'] ?? ($c['id'] ?? '');
            $val      = $values[$targetId] ?? null;
            $cVal     = $c['value'] ?? '';
            $cRule    = $c['rule'] ?? 'is';

            if (is_array($val)) {
                return $cRule === 'is' ? in_array($cVal, $val) : !in_array($cVal, $val);
            }
            if ($cRule === 'is')          return (string) $val === (string) $cVal;
            if ($cRule === 'is_not')      return (string) $val !== (string) $cVal;
            if ($cRule === 'contains')    return str_contains((string) $val, $cVal);
            if ($cRule === 'not_contains') return !str_contains((string) $val, $cVal);
            return false;
        }, $conditions);

        return strtolower($rule) === 'any'
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    private static function validateField(array $f, mixed $value): ?string
    {
        $req   = !empty($f['required']);
        $type  = $f['type'];
        $label = $f['label'] ?: 'This field';

        $empty = ($value === null || $value === '' || $value === [])
            || (is_array($value) && !array_filter($value, fn($v) => trim((string) $v) !== ''));

        if ($req && $empty) {
            return h($label) . ' is required.';
        }
        if ($empty) {
            return null;
        }

        if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'Please enter a valid email address.';
        }

        if ($type === 'name' && is_array($value)) {
            $cfg = $f['config'] ?? [];
            foreach (['fname', 'mname', 'lname'] as $part) {
                if (!empty($cfg[$part . '_required']) && empty(trim($value[$part] ?? ''))) {
                    return h($cfg[$part . '_label'] ?? ucfirst($part) . ' Name') . ' is required.';
                }
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Display / formatting
    // -----------------------------------------------------------------------

    /**
     * Format a stored field value for human display (admin view, PDF).
     * Returns escaped HTML.
     */
    public static function formatValue(array $field, mixed $value): string
    {
        $type = $field['type'];

        if ($value === null || $value === '' || $value === []) {
            return '<span style="color:#bbb;">—</span>';
        }

        if ($type === 'name' && is_array($value)) {
            $parts = array_filter([$value['prefix'] ?? '', $value['fname'] ?? '', $value['mname'] ?? '', $value['lname'] ?? '']);
            return h(implode(' ', $parts));
        }

        if ($type === 'address' && is_array($value)) {
            $parts = array_filter([
                $value['street'] ?? '',
                $value['line2']  ?? '',
                trim(($value['city'] ?? '') . ', ' . ($value['state'] ?? '') . ' ' . ($value['zip'] ?? ''), ', '),
                $value['country'] ?? '',
            ], fn($v) => trim($v, ', ') !== '');
            return nl2br(h(implode("\n", $parts)));
        }

        if ($type === 'checkbox' && is_array($value)) {
            $optMap = array_column($field['options'] ?? [], 'label', 'value');
            return h(implode(', ', array_map(fn($v) => $optMap[$v] ?? $v, $value)));
        }

        if ($type === 'radio') {
            foreach ($field['options'] ?? [] as $opt) {
                if ($opt['value'] === $value) {
                    return h($opt['label']);
                }
            }
        }

        if ($type === 'textarea') {
            return nl2br(h((string) $value));
        }

        return h((string) $value);
    }

    /**
     * Return all fields in display order (groups inlined as dividers, children following).
     */
    public static function flattenFields(array $fields): array
    {
        $byParent = ['__root__' => []];
        foreach ($fields as $f) {
            $byParent[$f['parent_group'] ?: '__root__'][] = $f;
        }

        $out  = [];
        $walk = function (string $pid) use (&$walk, &$byParent, &$out) {
            foreach ($byParent[$pid] ?? [] as $f) {
                $out[] = $f;
                if ($f['type'] === 'group') {
                    $walk($f['id']);
                }
            }
        };
        $walk('__root__');
        return $out;
    }

    // -----------------------------------------------------------------------
    // Notification email
    // -----------------------------------------------------------------------

    private static function sendNotification(array $form, array $fields, array $data, int $subId): void
    {
        $siteName = setting('site_name', 'Your Parish');
        $subject  = '[' . $siteName . '] New submission: ' . $form['title'] . ' #' . $subId;
        $flat     = self::flattenFields($fields);

        $body  = '<h2 style="color:#5d4037;">New Form Submission</h2>';
        $body .= '<p><strong>Form:</strong> ' . htmlspecialchars($form['title']) . '<br>';
        $body .= '<strong>Submission #:</strong> ' . $subId . '<br>';
        $body .= '<strong>Date:</strong> ' . date('F j, Y \a\t g:i a') . '</p><hr>';

        foreach ($flat as $f) {
            if ($f['type'] === 'group') {
                $body .= '<h3 style="color:#5d4037;margin-top:20px;border-bottom:1px solid #e0d6cc;">'
                       . htmlspecialchars($f['label']) . '</h3>';
                continue;
            }
            $val  = $data[$f['id']] ?? null;
            $body .= '<p><strong>' . htmlspecialchars($f['label']) . ':</strong><br>'
                   . self::formatValue($f, $val) . '</p>';
        }

        $body .= '<hr><p style="color:#aaa;font-size:12px;">Sent from ' . htmlspecialchars($siteName) . '</p>';

        try {
            if (class_exists('Mailer')) {
                Mailer::send($form['notify_email'], $subject, $body);
            } else {
                $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
                $headers .= "From: {$siteName} <noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";
                @mail($form['notify_email'], $subject, $body, $headers);
            }
        } catch (\Throwable) {
            // Fail silently — submission already stored
        }
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    private static function makeSlug(string $title, int $siteId): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(trim($title))), '-');
        $orig = $slug;
        $i    = 1;
        while (Database::fetch("SELECT id FROM custom_forms WHERE site_id = ? AND slug = ?", [$siteId, $slug])) {
            $slug = $orig . '-' . $i++;
        }
        return $slug;
    }

    private static function isBool(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    private static function extractConfig(array $f): array
    {
        $t = $f['type'];

        if ($t === 'name') {
            return [
                'prefix'             => self::isBool($f['prefix'] ?? false),
                'fname'              => self::isBool($f['fname'] ?? false),
                'mname'              => self::isBool($f['mname'] ?? false),
                'lname'              => self::isBool($f['lname'] ?? false),
                'fname_required'     => self::isBool($f['fname_required'] ?? false),
                'mname_required'     => self::isBool($f['mname_required'] ?? false),
                'lname_required'     => self::isBool($f['lname_required'] ?? false),
                'prefix_label'       => $f['prefix_label'] ?? 'Prefix',
                'fname_label'        => $f['fname_label']  ?? 'First Name',
                'mname_label'        => $f['mname_label']  ?? 'Middle Name',
                'lname_label'        => $f['lname_label']  ?? 'Last Name',
                'fname_placeholder'  => $f['fname_placeholder'] ?? '',
                'mname_placeholder'  => $f['mname_placeholder'] ?? '',
                'lname_placeholder'  => $f['lname_placeholder'] ?? '',
            ];
        }

        if ($t === 'address') {
            return [
                'street'          => self::isBool($f['street_address'] ?? false),
                'line2'           => self::isBool($f['address_line']   ?? false),
                'city'            => self::isBool($f['address_city']   ?? false),
                'state'           => self::isBool($f['address_state']  ?? false),
                'zip'             => self::isBool($f['address_zip']    ?? false),
                'country'         => self::isBool($f['address_country'] ?? false),
                'street_required' => self::isBool($f['street_address_required'] ?? false),
                'city_required'   => self::isBool($f['address_city_required']   ?? false),
                'state_required'  => self::isBool($f['address_state_required']  ?? false),
                'zip_required'    => self::isBool($f['address_zip_required']     ?? false),
                'country_required'=> self::isBool($f['address_country_required'] ?? false),
            ];
        }

        if ($t === 'upload') {
            return [
                'multiple' => ($f['file-type'] ?? 'single') === 'multiple',
                'max_mb'   => (int) ($f['upload-limit'] ?? 8),
            ];
        }

        if ($t === 'number') {
            return ['min' => $f['limit_min'] ?? '', 'max' => $f['limit_max'] ?? ''];
        }

        return [];
    }
}
