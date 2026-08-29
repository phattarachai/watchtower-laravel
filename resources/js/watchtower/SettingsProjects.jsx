import { useState } from 'react'

import { BUTTON, BUTTON_ACCENT, CARD, Chip, INPUT, LABEL } from './badges'
import { CheckIcon, ChevronIcon, CopyIcon, RefreshIcon } from './icons'
import { copyText, cx, firstError, sendJson, withId } from './lib'

/**
 * Projects and their wiring: DSN, key rotation, and the copy-paste install
 * snippet for each platform an SDK can point at this server.
 */
export function SettingsProjects({ projects, platforms, endpoints, csrfToken }) {
  const [items, setItems] = useState(projects ?? [])
  const [notice, setNotice] = useState(null)

  const replace = (project) => {
    setItems((list) => list.map((item) => (item.id === project.id ? project : item)))
  }

  return (
    <div className="tw:flex tw:flex-col tw:gap-4">
      <h1 className="tw:text-base tw:font-semibold tw:text-[var(--wt-text)]">Projects</h1>

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

      <CreateProject
        platforms={platforms}
        endpoints={endpoints}
        csrfToken={csrfToken}
        onCreated={(project) => {
          setItems((list) => [...list, project])
          setNotice({ tone: 'ok', text: `Created “${project.name}”.` })
        }}
        onError={(text) => setNotice({ tone: 'error', text })}
      />

      <div className="tw:flex tw:flex-col tw:gap-3">
        {items.map((project) => (
          <ProjectCard
            key={project.id}
            project={project}
            endpoints={endpoints}
            csrfToken={csrfToken}
            onUpdated={(updated) => {
              replace(updated)
              setNotice({ tone: 'ok', text: `Updated “${updated.name}”.` })
            }}
            onRotated={(updated) => {
              replace(updated)
              setNotice({ tone: 'ok', text: `Rotated the key for “${updated.name}”.` })
            }}
            onError={(text) => setNotice({ tone: 'error', text })}
          />
        ))}
      </div>
    </div>
  )
}

function CreateProject({ platforms, endpoints, csrfToken, onCreated, onError }) {
  const [name, setName] = useState('')
  const [platform, setPlatform] = useState(platforms?.[0] ?? 'laravel')
  const [busy, setBusy] = useState(false)

  const submit = async (event) => {
    event.preventDefault()
    setBusy(true)

    const { ok, data } = await sendJson(endpoints.projectStore, 'POST', csrfToken, {
      name,
      platform,
    })

    setBusy(false)

    if (!ok) {
      onError(firstError(data))
      return
    }

    setName('')
    onCreated(data.project)
  }

  return (
    <form onSubmit={submit} className={cx(CARD, 'tw:flex tw:flex-wrap tw:items-end tw:gap-3 tw:p-4')}>
      <div className="tw:min-w-48 tw:flex-1">
        <span className={LABEL}>New project name</span>
        <input
          className={INPUT}
          value={name}
          onChange={(event) => setName(event.target.value)}
          placeholder="Storefront"
        />
      </div>
      <div className="tw:w-40">
        <span className={LABEL}>Platform</span>
        <select
          className={INPUT}
          value={platform}
          onChange={(event) => setPlatform(event.target.value)}
        >
          {(platforms ?? []).map((item) => (
            <option key={item} value={item}>
              {item}
            </option>
          ))}
        </select>
      </div>
      <button type="submit" className={BUTTON_ACCENT} disabled={busy}>
        Create project
      </button>
    </form>
  )
}

function ProjectCard({ project, endpoints, csrfToken, onUpdated, onRotated, onError }) {
  const [name, setName] = useState(project.name)
  const [busy, setBusy] = useState(false)

  const save = async (changes) => {
    setBusy(true)

    const { ok, data } = await sendJson(
      withId(endpoints.projectUpdate, project.id),
      'PATCH',
      csrfToken,
      { name, is_active: project.is_active, ...changes },
    )

    setBusy(false)

    if (!ok) {
      onError(firstError(data))
      return
    }

    onUpdated(data.project)
  }

  const rotate = async () => {
    setBusy(true)

    const { ok, data } = await sendJson(
      withId(endpoints.projectRotateKey, project.id),
      'POST',
      csrfToken,
      {},
    )

    setBusy(false)

    if (!ok) {
      onError(firstError(data))
      return
    }

    onRotated(data.project)
  }

  return (
    <section className={cx(CARD, 'tw:p-4')}>
      <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-3">
        <input
          className={cx(INPUT, 'tw:max-w-56')}
          value={name}
          onChange={(event) => setName(event.target.value)}
        />
        <Chip>{project.platform}</Chip>
        <Chip>{project.slug}</Chip>
        {!project.is_active && <Chip>disabled</Chip>}

        <div className="tw:ml-auto tw:flex tw:items-center tw:gap-2">
          <button type="button" className={BUTTON} disabled={busy} onClick={() => save({})}>
            Save
          </button>
          <button
            type="button"
            className={BUTTON}
            disabled={busy}
            onClick={() => save({ is_active: !project.is_active })}
          >
            {project.is_active ? 'Disable' : 'Enable'}
          </button>
        </div>
      </div>

      <div className="tw:mt-3 tw:flex tw:flex-col tw:gap-2">
        <CopyRow label="DSN" value={project.dsn} />
        <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
          <span className="tw:w-12 tw:shrink-0 tw:text-[11px] tw:text-[var(--wt-text-muted)]">
            Key
          </span>
          <code className="wt-code tw:text-[var(--wt-text-muted)]">{project.masked_key}</code>
          <button type="button" className={BUTTON} disabled={busy} onClick={rotate}>
            <RefreshIcon className="tw:h-3.5 tw:w-3.5" />
            Rotate key
          </button>
        </div>
      </div>

      <Snippets snippets={project.snippets} />
    </section>
  )
}

function CopyRow({ label, value }) {
  const [copied, setCopied] = useState(false)

  const copy = async () => {
    const ok = await copyText(value)
    setCopied(ok)
  }

  return (
    <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
      <span className="tw:w-12 tw:shrink-0 tw:text-[11px] tw:text-[var(--wt-text-muted)]">
        {label}
      </span>
      <code className="wt-code tw:min-w-0 tw:flex-1 tw:truncate tw:rounded tw:bg-[var(--wt-code-bg)] tw:px-2 tw:py-1 tw:text-[var(--wt-text)]">
        {value}
      </code>
      <button type="button" className={BUTTON} onClick={copy}>
        {copied ? <CheckIcon className="tw:h-3.5 tw:w-3.5" /> : <CopyIcon className="tw:h-3.5 tw:w-3.5" />}
        {copied ? 'Copied' : 'Copy'}
      </button>
    </div>
  )
}

function Snippets({ snippets }) {
  const [open, setOpen] = useState(null)
  const list = Array.isArray(snippets) ? snippets : []

  if (list.length === 0) {
    return null
  }

  return (
    <div className="tw:mt-3 tw:divide-y tw:divide-[var(--wt-border)] tw:border-t tw:border-[var(--wt-border)]">
      {list.map((snippet) => (
        <div key={snippet.key}>
          <button
            type="button"
            onClick={() => setOpen((current) => (current === snippet.key ? null : snippet.key))}
            className="tw:flex tw:w-full tw:items-center tw:gap-2 tw:py-2 tw:text-left tw:text-xs tw:text-[var(--wt-text-muted)] tw:hover:text-[var(--wt-text)]"
          >
            <ChevronIcon
              className={cx('tw:h-3 tw:w-3', open === snippet.key && 'tw:rotate-90')}
            />
            {snippet.label}
          </button>
          {open === snippet.key && (
            <pre className="wt-code tw:mb-2 tw:overflow-x-auto tw:rounded tw:bg-[var(--wt-code-bg)] tw:p-3 tw:text-[var(--wt-text)]">
              {snippet.code}
            </pre>
          )}
        </div>
      ))}
    </div>
  )
}
