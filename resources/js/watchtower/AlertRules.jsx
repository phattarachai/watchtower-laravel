import { useState } from 'react'

import { BUTTON, BUTTON_ACCENT, CARD, Chip, INPUT, LABEL } from './badges'
import { CloseIcon, SendIcon, TrashIcon } from './icons'
import { cx, firstError, sendJson, withId } from './lib'

const BLANK = {
  id: null,
  project_id: '',
  name: '',
  type: 'new_issue',
  environment: '',
  min_level: 'error',
  threshold_count: '',
  threshold_window_seconds: '',
  cooldown_seconds: 900,
  emails: [],
  is_active: true,
}

const NEEDS_THRESHOLD = ['threshold', 'milestone']

/**
 * Alert rules: a table on the left, an inline side panel for create/edit. Every
 * write is an XHR against the endpoints the server handed over — the list is
 * patched in place rather than reloaded.
 */
export function AlertRules({ rules, projects, options, endpoints, csrfToken }) {
  const [items, setItems] = useState(rules ?? [])
  const [draft, setDraft] = useState(null)
  const [notice, setNotice] = useState(null)

  const upsert = (rule) => {
    setItems((list) => {
      const exists = list.some((item) => item.id === rule.id)
      return exists ? list.map((item) => (item.id === rule.id ? rule : item)) : [...list, rule]
    })
  }

  const remove = async (rule) => {
    const { ok, data } = await sendJson(
      withId(endpoints.alertDestroy, rule.id),
      'DELETE',
      csrfToken,
    )

    if (!ok) {
      setNotice({ tone: 'error', text: firstError(data) })
      return
    }

    setItems((list) => list.filter((item) => item.id !== rule.id))
    setNotice({ tone: 'ok', text: `Deleted “${rule.name}”.` })
  }

  const sendTest = async (rule) => {
    const { ok, data } = await sendJson(withId(endpoints.alertTest, rule.id), 'POST', csrfToken, {})

    setNotice(
      ok
        ? { tone: 'ok', text: `Test alert queued for ${data.recipients} recipient(s).` }
        : { tone: 'error', text: firstError(data) },
    )
  }

  return (
    <div className="tw:flex tw:flex-col tw:gap-4">
      <div className="tw:flex tw:items-center tw:gap-3">
        <h1 className="tw:text-base tw:font-semibold tw:text-[var(--wt-text)]">Alert rules</h1>
        <button
          type="button"
          className={cx(BUTTON_ACCENT, 'tw:ml-auto')}
          onClick={() => setDraft({ ...BLANK, project_id: projects?.[0]?.id ?? '' })}
        >
          New rule
        </button>
      </div>

      {notice && (
        <p
          className={cx(
            'tw:text-xs',
            notice.tone === 'error'
              ? 'tw:text-[var(--wt-level-error)]'
              : 'tw:text-[var(--wt-status-resolved)]',
          )}
        >
          {notice.text}
        </p>
      )}

      <div className="tw:grid tw:gap-4 tw:lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className={cx(CARD, 'tw:overflow-hidden')}>
          {items.length === 0 ? (
            <p className="tw:px-4 tw:py-10 tw:text-center tw:text-xs tw:text-[var(--wt-text-muted)]">
              No alert rules yet.
            </p>
          ) : (
            <ul className="tw:divide-y tw:divide-[var(--wt-border)]">
              {items.map((rule) => (
                <RuleRow
                  key={rule.id}
                  rule={rule}
                  onEdit={() => setDraft(toDraft(rule))}
                  onDelete={() => remove(rule)}
                  onTest={() => sendTest(rule)}
                />
              ))}
            </ul>
          )}
        </div>

        {draft && (
          <RuleForm
            draft={draft}
            projects={projects}
            options={options}
            endpoints={endpoints}
            csrfToken={csrfToken}
            onClose={() => setDraft(null)}
            onSaved={(rule) => {
              upsert(rule)
              setDraft(null)
              setNotice({ tone: 'ok', text: `Saved “${rule.name}”.` })
            }}
          />
        )}
      </div>
    </div>
  )
}

function RuleRow({ rule, onEdit, onDelete, onTest }) {
  return (
    <li className="tw:flex tw:flex-wrap tw:items-center tw:gap-3 tw:px-4 tw:py-3">
      <div className="tw:min-w-0 tw:flex-1">
        <div className="tw:flex tw:items-center tw:gap-2">
          <span className="tw:truncate tw:font-medium tw:text-[var(--wt-text)]">{rule.name}</span>
          {!rule.is_active && <Chip>paused</Chip>}
        </div>
        <div className="tw:mt-1 tw:flex tw:flex-wrap tw:items-center tw:gap-1.5 tw:text-[11px] tw:text-[var(--wt-text-muted)]">
          <Chip>{rule.type}</Chip>
          {rule.project?.name && <Chip>{rule.project.name}</Chip>}
          <Chip>≥ {rule.min_level}</Chip>
          {rule.environment && <Chip>{rule.environment}</Chip>}
          {rule.threshold_count && (
            <Chip>
              {rule.threshold_count}
              {rule.threshold_window_seconds
                ? ` / ${Math.round(rule.threshold_window_seconds / 60)}m`
                : ''}
            </Chip>
          )}
          <Chip>{rule.emails?.length ?? 0} recipient(s)</Chip>
        </div>
      </div>

      <div className="tw:flex tw:items-center tw:gap-2">
        <button type="button" className={BUTTON} onClick={onTest}>
          <SendIcon className="tw:h-3.5 tw:w-3.5" />
          Send test
        </button>
        <button type="button" className={BUTTON} onClick={onEdit}>
          Edit
        </button>
        <button type="button" className={BUTTON} onClick={onDelete} aria-label="Delete rule">
          <TrashIcon className="tw:h-3.5 tw:w-3.5" />
        </button>
      </div>
    </li>
  )
}

function RuleForm({ draft, projects, options, endpoints, csrfToken, onClose, onSaved }) {
  const [form, setForm] = useState(draft)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  const set = (key, value) => setForm((current) => ({ ...current, [key]: value }))

  const submit = async (event) => {
    event.preventDefault()
    setBusy(true)
    setError(null)

    const target = form.id
      ? { url: withId(endpoints.alertUpdate, form.id), method: 'PATCH' }
      : { url: endpoints.alertStore, method: 'POST' }

    const { ok, data } = await sendJson(target.url, target.method, csrfToken, toPayload(form))

    setBusy(false)

    if (!ok) {
      setError(firstError(data))
      return
    }

    onSaved(data.rule)
  }

  const showThreshold = NEEDS_THRESHOLD.includes(form.type)

  return (
    <form onSubmit={submit} className={cx(CARD, 'tw:h-fit tw:p-4')}>
      <div className="tw:mb-3 tw:flex tw:items-center">
        <h2 className="tw:text-sm tw:font-semibold tw:text-[var(--wt-text)]">
          {form.id ? 'Edit rule' : 'New rule'}
        </h2>
        <button
          type="button"
          onClick={onClose}
          aria-label="Close"
          className="tw:ml-auto tw:text-[var(--wt-text-faint)] tw:hover:text-[var(--wt-text)]"
        >
          <CloseIcon className="tw:h-4 tw:w-4" />
        </button>
      </div>

      <div className="tw:flex tw:flex-col tw:gap-3">
        <Field label="Name">
          <input
            className={INPUT}
            value={form.name}
            onChange={(event) => set('name', event.target.value)}
          />
        </Field>

        <Field label="Project">
          <select
            className={INPUT}
            value={form.project_id}
            onChange={(event) => set('project_id', event.target.value)}
          >
            <option value="">Select a project</option>
            {(projects ?? []).map((project) => (
              <option key={project.id} value={project.id}>
                {project.name}
              </option>
            ))}
          </select>
        </Field>

        <Field label="Type">
          <select
            className={INPUT}
            value={form.type}
            onChange={(event) => set('type', event.target.value)}
          >
            {(options?.types ?? []).map((type) => (
              <option key={type.value} value={type.value}>
                {type.label}
              </option>
            ))}
          </select>
        </Field>

        <div className="tw:grid tw:grid-cols-2 tw:gap-3">
          <Field label="Minimum level">
            <select
              className={INPUT}
              value={form.min_level}
              onChange={(event) => set('min_level', event.target.value)}
            >
              {(options?.levels ?? []).map((level) => (
                <option key={level} value={level}>
                  {level}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Environment">
            <input
              className={INPUT}
              placeholder="any"
              value={form.environment ?? ''}
              onChange={(event) => set('environment', event.target.value)}
            />
          </Field>
        </div>

        {showThreshold && (
          <div className="tw:grid tw:grid-cols-2 tw:gap-3">
            <Field label="Event count">
              <input
                className={INPUT}
                type="number"
                min="1"
                value={form.threshold_count ?? ''}
                onChange={(event) => set('threshold_count', event.target.value)}
              />
            </Field>
            <Field label="Window (seconds)">
              <input
                className={INPUT}
                type="number"
                min="60"
                value={form.threshold_window_seconds ?? ''}
                onChange={(event) => set('threshold_window_seconds', event.target.value)}
              />
            </Field>
          </div>
        )}

        <Field label="Cooldown (seconds)">
          <input
            className={INPUT}
            type="number"
            min="0"
            value={form.cooldown_seconds}
            onChange={(event) => set('cooldown_seconds', event.target.value)}
          />
        </Field>

        <Field label="Recipients">
          <EmailInput emails={form.emails} onChange={(emails) => set('emails', emails)} />
        </Field>

        <label className="tw:flex tw:items-center tw:gap-2 tw:text-xs tw:text-[var(--wt-text)]">
          <input
            type="checkbox"
            checked={Boolean(form.is_active)}
            onChange={(event) => set('is_active', event.target.checked)}
          />
          Active
        </label>

        {error && <p className="tw:text-xs tw:text-[var(--wt-level-error)]">{error}</p>}

        <div className="tw:flex tw:items-center tw:gap-2">
          <button type="submit" className={BUTTON_ACCENT} disabled={busy}>
            Save rule
          </button>
          <button type="button" className={BUTTON} onClick={onClose}>
            Cancel
          </button>
        </div>
      </div>
    </form>
  )
}

function EmailInput({ emails, onChange }) {
  const [value, setValue] = useState('')
  const list = Array.isArray(emails) ? emails : []

  const commit = () => {
    const email = value.trim()
    if (email === '' || list.includes(email)) {
      setValue('')
      return
    }
    onChange([...list, email])
    setValue('')
  }

  return (
    <div className="tw:flex tw:flex-col tw:gap-1.5">
      {list.length > 0 && (
        <div className="tw:flex tw:flex-wrap tw:gap-1.5">
          {list.map((email) => (
            <span
              key={email}
              className="tw:inline-flex tw:items-center tw:gap-1 tw:rounded tw:bg-[var(--wt-hover)] tw:px-1.5 tw:py-0.5 tw:text-[11px] tw:text-[var(--wt-text)]"
            >
              {email}
              <button
                type="button"
                aria-label={`Remove ${email}`}
                onClick={() => onChange(list.filter((item) => item !== email))}
                className="tw:text-[var(--wt-text-faint)] tw:hover:text-[var(--wt-text)]"
              >
                <CloseIcon className="tw:h-3 tw:w-3" />
              </button>
            </span>
          ))}
        </div>
      )}
      <input
        className={INPUT}
        placeholder="name@example.com, then Enter"
        value={value}
        onChange={(event) => setValue(event.target.value)}
        onBlur={commit}
        onKeyDown={(event) => {
          if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault()
            commit()
          }
        }}
      />
    </div>
  )
}

function Field({ label, children }) {
  return (
    <div>
      <span className={LABEL}>{label}</span>
      {children}
    </div>
  )
}

function toDraft(rule) {
  return {
    ...BLANK,
    ...rule,
    environment: rule.environment ?? '',
    threshold_count: rule.threshold_count ?? '',
    threshold_window_seconds: rule.threshold_window_seconds ?? '',
    emails: rule.emails ?? [],
  }
}

function toPayload(form) {
  return {
    project_id: form.project_id === '' ? null : Number(form.project_id),
    name: form.name,
    type: form.type,
    environment: form.environment === '' ? null : form.environment,
    min_level: form.min_level,
    threshold_count: form.threshold_count === '' ? null : Number(form.threshold_count),
    threshold_window_seconds:
      form.threshold_window_seconds === '' ? null : Number(form.threshold_window_seconds),
    cooldown_seconds: Number(form.cooldown_seconds),
    emails: form.emails,
    is_active: Boolean(form.is_active),
  }
}
