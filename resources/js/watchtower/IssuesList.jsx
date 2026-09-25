import { useState } from 'react'

import { BUTTON, BUTTON_ACCENT, CARD, INPUT, LevelBadge, StatusPill } from './badges'
import { CheckIcon, ChevronIcon, SearchIcon, TrashIcon } from './icons'
import { cx, firstError, formatDateTime, relativeTime, sendJson, withId, withQuery } from './lib'

const STATUS_TABS = [
  { key: '', label: 'All', count: 'all' },
  { key: 'unresolved', label: 'Unresolved', count: 'unresolved' },
  { key: 'resolved', label: 'Resolved', count: 'resolved' },
  { key: 'ignored', label: 'Ignored', count: 'ignored' },
  { key: 'snoozed', label: 'Snoozed', count: 'snoozed' },
]

const LEVELS = ['debug', 'info', 'warning', 'error', 'fatal']

const SNOOZE_OPTIONS = [
  { minutes: 60, label: '1 hour' },
  { minutes: 1440, label: '24 hours' },
  { minutes: 10080, label: '7 days' },
  { minutes: 43200, label: '30 days' },
]

const CHECKBOX = 'tw:h-3.5 tw:w-3.5 tw:cursor-pointer tw:accent-[var(--wt-accent)]'

/**
 * The issue inbox: filter bar, one row per group, server-side pagination.
 * Filtering is a full navigation so the URL always describes what is on screen.
 */
export function IssuesList({ issues, filters, projects, environments, counts, endpoints, csrfToken }) {
  const rows = issues?.data ?? []
  const meta = issues?.meta ?? {}
  const [query, setQuery] = useState(filters?.q ?? '')
  const [selected, setSelected] = useState(() => new Set())
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const toggle = (id) => {
    setSelected((current) => {
      const next = new Set(current)
      next.has(id) ? next.delete(id) : next.add(id)
      return next
    })
  }

  const toggleAll = () => {
    setSelected(selected.size === rows.length ? new Set() : new Set(rows.map((issue) => issue.id)))
  }

  const runBulk = async (url, method, body) => {
    setBusy(true)
    setError(null)
    const response = await sendJson(url, method, csrfToken, { ids: [...selected], ...body })
    if (response.ok) {
      window.location.reload()
      return
    }
    setBusy(false)
    setError(firstError(response.data))
  }

  const bulkStatus = (status, snoozeMinutes) =>
    runBulk(endpoints.issueBulkStatus, 'PATCH', { status, snooze_minutes: snoozeMinutes ?? null })

  const bulkDelete = () => {
    if (window.confirm(`Delete ${selected.size} issue(s) and all their events? This cannot be undone.`)) {
      runBulk(endpoints.issueBulkDestroy, 'DELETE')
    }
  }

  const go = (changes) => {
    window.location.assign(withQuery(endpoints.issues, { ...filters, page: null, ...changes }))
  }

  return (
    <div className="tw:flex tw:flex-col tw:gap-4">
      <StatusTabs counts={counts} active={filters?.status ?? ''} onSelect={(s) => go({ status: s })} />

      <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
        <form
          className="tw:relative tw:min-w-56 tw:flex-1"
          onSubmit={(event) => {
            event.preventDefault()
            go({ q: query })
          }}
        >
          <SearchIcon className="tw:pointer-events-none tw:absolute tw:top-2 tw:left-2.5 tw:h-4 tw:w-4 tw:text-[var(--wt-text-faint)]" />
          <input
            className={cx(INPUT, 'tw:pl-8')}
            placeholder="Search issue titles"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
          />
        </form>

        <Select
          value={filters?.project_id ?? ''}
          onChange={(value) => go({ project_id: value })}
          placeholder="All projects"
          options={(projects ?? []).map((p) => ({ value: p.id, label: p.name }))}
        />
        <Select
          value={filters?.level ?? ''}
          onChange={(value) => go({ level: value })}
          placeholder="All levels"
          options={LEVELS.map((level) => ({ value: level, label: level }))}
        />
        <Select
          value={filters?.environment ?? ''}
          onChange={(value) => go({ environment: value })}
          placeholder="All environments"
          options={(environments ?? []).map((env) => ({ value: env, label: env }))}
        />
      </div>

      <div className={cx(CARD, 'tw:overflow-hidden')}>
        {rows.length === 0 ? (
          <p className="tw:px-4 tw:py-10 tw:text-center tw:text-xs tw:text-[var(--wt-text-muted)]">
            No issues match these filters.
          </p>
        ) : (
          <>
            <BulkBar
              total={rows.length}
              count={selected.size}
              busy={busy}
              error={error}
              onToggleAll={toggleAll}
              onStatus={bulkStatus}
              onDelete={bulkDelete}
            />
            <ul className="tw:divide-y tw:divide-[var(--wt-border)]">
              {rows.map((issue) => (
                <IssueRow
                  key={issue.id}
                  issue={issue}
                  href={withId(endpoints.issue, issue.id)}
                  checked={selected.has(issue.id)}
                  onToggle={() => toggle(issue.id)}
                />
              ))}
            </ul>
          </>
        )}
      </div>

      <Pagination meta={meta} filters={filters} endpoint={endpoints.issues} />
    </div>
  )
}

function StatusTabs({ counts, active, onSelect }) {
  return (
    <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-1">
      {STATUS_TABS.map((tab) => (
        <button
          key={tab.key || 'all'}
          type="button"
          onClick={() => onSelect(tab.key)}
          className={cx(
            'tw:flex tw:items-center tw:gap-1.5 tw:rounded-md tw:px-2.5 tw:py-1.5 tw:text-xs tw:font-medium',
            active === tab.key
              ? 'tw:bg-[var(--wt-accent-soft)] tw:text-[var(--wt-accent)]'
              : 'tw:text-[var(--wt-text-muted)] tw:hover:bg-[var(--wt-hover)]',
          )}
        >
          {tab.label}
          <span className="tw:text-[var(--wt-text-faint)]">{counts?.[tab.count] ?? 0}</span>
        </button>
      ))}
    </div>
  )
}

function BulkBar({ total, count, busy, error, onToggleAll, onStatus, onDelete }) {
  const allChecked = count > 0 && count === total

  return (
    <div className="tw:flex tw:min-h-11 tw:flex-wrap tw:items-center tw:gap-3 tw:border-b tw:border-[var(--wt-border)] tw:bg-[var(--wt-toolbar)] tw:px-4 tw:py-2">
      <input
        type="checkbox"
        aria-label="Select all issues on this page"
        className={CHECKBOX}
        checked={allChecked}
        ref={(input) => {
          if (input) {
            input.indeterminate = count > 0 && !allChecked
          }
        }}
        onChange={onToggleAll}
      />

      {count === 0 ? (
        <span className="tw:text-[11px] tw:text-[var(--wt-text-faint)]">Select issues to resolve, ignore, snooze or delete</span>
      ) : (
        <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
          <span className="tw:text-xs tw:font-medium tw:text-[var(--wt-text)]">{count} selected</span>
          <button type="button" disabled={busy} onClick={() => onStatus('resolved')} className={BUTTON_ACCENT}>
            <CheckIcon className="tw:h-3.5 tw:w-3.5" />
            Resolve
          </button>
          <button type="button" disabled={busy} onClick={() => onStatus('unresolved')} className={BUTTON}>
            Unresolve
          </button>
          <button type="button" disabled={busy} onClick={() => onStatus('ignored')} className={BUTTON}>
            Ignore
          </button>
          <SnoozeMenu disabled={busy} onSnooze={(minutes) => onStatus('snoozed', minutes)} />
          <button
            type="button"
            disabled={busy}
            onClick={onDelete}
            className={cx(BUTTON, 'tw:text-[var(--wt-level-error)]')}
          >
            <TrashIcon className="tw:h-3.5 tw:w-3.5" />
            Delete
          </button>
        </div>
      )}

      {error && <span className="tw:text-[11px] tw:text-[var(--wt-level-error)]">{error}</span>}
    </div>
  )
}

function SnoozeMenu({ disabled, onSnooze }) {
  const [open, setOpen] = useState(false)

  return (
    <div className="tw:relative">
      <button type="button" disabled={disabled} onClick={() => setOpen(!open)} className={BUTTON}>
        Snooze
        <ChevronIcon className="tw:h-3 tw:w-3 tw:rotate-90" />
      </button>
      {open && (
        <div className="tw:absolute tw:left-0 tw:z-10 tw:mt-1 tw:min-w-32 tw:overflow-hidden tw:rounded-md tw:border tw:border-[var(--wt-border)] tw:bg-[var(--wt-raised)] tw:shadow-lg">
          {SNOOZE_OPTIONS.map((option) => (
            <button
              key={option.minutes}
              type="button"
              onClick={() => {
                setOpen(false)
                onSnooze(option.minutes)
              }}
              className="tw:block tw:w-full tw:px-3 tw:py-1.5 tw:text-left tw:text-xs tw:text-[var(--wt-text)] tw:hover:bg-[var(--wt-hover)]"
            >
              {option.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

function IssueRow({ issue, href, checked, onToggle }) {
  return (
    <li className={cx('tw:flex tw:items-center', checked && 'tw:bg-[var(--wt-accent-soft)]')}>
      <label className="tw:flex tw:cursor-pointer tw:self-stretch tw:items-center tw:pl-4">
        <input
          type="checkbox"
          aria-label={`Select ${issue.title}`}
          className={CHECKBOX}
          checked={checked}
          onChange={onToggle}
        />
      </label>
      <a
        href={href}
        className="tw:flex tw:min-w-0 tw:flex-1 tw:items-center tw:gap-3 tw:px-4 tw:py-3 tw:hover:bg-[var(--wt-hover)]"
      >
        <div className="tw:min-w-0 tw:flex-1">
          <div className="tw:flex tw:items-center tw:gap-2">
            <LevelBadge level={issue.level} />
            <span className="tw:truncate tw:font-medium tw:text-[var(--wt-text)]">
              {issue.title}
            </span>
          </div>
          <div className="tw:mt-1 tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:text-[11px] tw:text-[var(--wt-text-muted)]">
            {issue.project?.name && <span>{issue.project.name}</span>}
            {issue.platform && <span>· {issue.platform}</span>}
            <span title={formatDateTime(issue.last_seen_at)}>
              · last seen {relativeTime(issue.last_seen_at)}
            </span>
            <span title={formatDateTime(issue.first_seen_at)}>
              · first seen {relativeTime(issue.first_seen_at)}
            </span>
          </div>
        </div>

        <div className="tw:flex tw:shrink-0 tw:items-center tw:gap-4">
          <Metric value={issue.event_count} label="events" />
          <Metric value={issue.user_count} label="users" />
          <StatusPill status={issue.status} />
        </div>
      </a>
    </li>
  )
}

function Metric({ value, label }) {
  return (
    <div className="tw:w-14 tw:text-right">
      <div className="tw:text-sm tw:font-semibold tw:text-[var(--wt-text)]">{value ?? 0}</div>
      <div className="tw:text-[10px] tw:text-[var(--wt-text-faint)]">{label}</div>
    </div>
  )
}

function Select({ value, onChange, placeholder, options }) {
  return (
    <select
      value={value ?? ''}
      onChange={(event) => onChange(event.target.value)}
      className="tw:rounded-md tw:border tw:border-[var(--wt-border)] tw:bg-[var(--wt-bg)] tw:px-2 tw:py-1.5 tw:text-xs tw:text-[var(--wt-text)] tw:outline-none"
    >
      <option value="">{placeholder}</option>
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  )
}

function Pagination({ meta, filters, endpoint }) {
  const current = meta?.current_page ?? 1
  const last = meta?.last_page ?? 1

  if (last <= 1) {
    return null
  }

  const link = (page) => withQuery(endpoint, { ...filters, page })

  return (
    <div className="tw:flex tw:items-center tw:justify-between tw:text-xs tw:text-[var(--wt-text-muted)]">
      <span>
        {meta.from ?? 0}–{meta.to ?? 0} of {meta.total ?? 0}
      </span>
      <div className="tw:flex tw:items-center tw:gap-2">
        <PageLink href={link(current - 1)} disabled={current <= 1}>
          Previous
        </PageLink>
        <span>
          Page {current} / {last}
        </span>
        <PageLink href={link(current + 1)} disabled={current >= last}>
          Next
        </PageLink>
      </div>
    </div>
  )
}

function PageLink({ href, disabled, children }) {
  if (disabled) {
    return <span className="tw:text-[var(--wt-text-faint)]">{children}</span>
  }

  return (
    <a
      href={href}
      rel="nofollow"
      className="tw:rounded tw:border tw:border-[var(--wt-border)] tw:px-2 tw:py-1 tw:hover:bg-[var(--wt-hover)]"
    >
      {children}
    </a>
  )
}
