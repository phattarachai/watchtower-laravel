import { useMemo, useState } from 'react'

import { BUTTON, BUTTON_ACCENT, CARD, Chip, LevelBadge, StatusPill } from './badges'
import { CheckIcon, ChevronIcon, CopyIcon } from './icons'
import {
  copyText,
  cx,
  firstError,
  flattenPairs,
  formatDateTime,
  isVendorFrame,
  relativeTime,
  sendJson,
  shortenPath,
  stringify,
  withId,
  withQuery,
} from './lib'

const TABS = [
  { key: 'stacktrace', label: 'Stacktrace' },
  { key: 'request', label: 'Request' },
  { key: 'breadcrumbs', label: 'Breadcrumbs' },
  { key: 'user', label: 'User' },
  { key: 'tags', label: 'Tags' },
]

const ACTIONS = [
  { status: 'resolved', label: 'Resolve' },
  { status: 'unresolved', label: 'Unresolve' },
  { status: 'ignored', label: 'Ignore' },
  { status: 'snoozed', label: 'Snooze 24h', snoozeMinutes: 1440 },
]

export function IssueDetail({ issue, event, events, navigation, endpoints, csrfToken, markdown }) {
  const [current, setCurrent] = useState(issue)
  const [tab, setTab] = useState('stacktrace')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const changeStatus = async (status, snoozeMinutes) => {
    setBusy(true)
    setError(null)

    const { ok, data } = await sendJson(
      withId(endpoints.issueStatus, current.id),
      'PATCH',
      csrfToken,
      { status, snooze_minutes: snoozeMinutes ?? null },
    )

    setBusy(false)

    if (!ok) {
      setError(firstError(data))
      return
    }

    setCurrent(data.issue ?? current)
  }

  return (
    <div className="tw:flex tw:flex-col tw:gap-4">
      <Header
        issue={current}
        busy={busy}
        error={error}
        backHref={endpoints.issues}
        onAction={changeStatus}
        markdown={markdown}
      />

      <div className="tw:grid tw:gap-4 tw:lg:grid-cols-[minmax(0,1fr)_260px]">
        <div className="tw:flex tw:min-w-0 tw:flex-col tw:gap-3">
          <EventBar event={event} navigation={navigation} issue={current} endpoints={endpoints} />

          <div className={cx(CARD, 'tw:overflow-hidden')}>
            <div className="tw:flex tw:gap-1 tw:border-b tw:border-[var(--wt-border)] tw:px-2 tw:py-1.5">
              {TABS.map((item) => (
                <button
                  key={item.key}
                  type="button"
                  onClick={() => setTab(item.key)}
                  className={cx(
                    'tw:rounded tw:px-2.5 tw:py-1 tw:text-xs tw:font-medium',
                    tab === item.key
                      ? 'tw:bg-[var(--wt-accent-soft)] tw:text-[var(--wt-accent)]'
                      : 'tw:text-[var(--wt-text-muted)] tw:hover:bg-[var(--wt-hover)]',
                  )}
                >
                  {item.label}
                </button>
              ))}
            </div>
            <TabBody tab={tab} event={event} />
          </div>
        </div>

        <EventSidebar events={events} event={event} issue={current} endpoints={endpoints} />
      </div>
    </div>
  )
}

function Header({ issue, busy, error, backHref, onAction, markdown }) {
  return (
    <div className={cx(CARD, 'tw:p-4')}>
      <a href={backHref} className="tw:text-[11px] tw:text-[var(--wt-text-muted)] tw:hover:underline">
        ← Back to issues
      </a>

      <div className="tw:mt-2 tw:flex tw:flex-wrap tw:items-start tw:gap-3">
        <div className="tw:min-w-0 tw:flex-1">
          <div className="tw:flex tw:items-center tw:gap-2">
            <LevelBadge level={issue.level} />
            <StatusPill status={issue.status} />
          </div>
          <h1 className="tw:mt-1.5 tw:text-base tw:font-semibold tw:break-words tw:text-[var(--wt-text)]">
            {issue.title}
          </h1>
          <div className="tw:mt-1 tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:text-[11px] tw:text-[var(--wt-text-muted)]">
            {issue.project?.name && <span>{issue.project.name}</span>}
            <span title={formatDateTime(issue.first_seen_at)}>
              · first seen {relativeTime(issue.first_seen_at)}
            </span>
            <span title={formatDateTime(issue.last_seen_at)}>
              · last seen {relativeTime(issue.last_seen_at)}
            </span>
            <span>· {issue.event_count ?? 0} events</span>
            <span>· {issue.user_count ?? 0} users</span>
          </div>
        </div>

        <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
          <CopyMarkdownButton markdown={markdown} />
          {ACTIONS.map((action) => (
            <button
              key={action.status}
              type="button"
              disabled={busy || issue.status === action.status}
              onClick={() => onAction(action.status, action.snoozeMinutes)}
              className={action.status === 'resolved' ? BUTTON_ACCENT : BUTTON}
            >
              {action.label}
            </button>
          ))}
        </div>
      </div>

      {error && <p className="tw:mt-2 tw:text-xs tw:text-[var(--wt-level-error)]">{error}</p>}
    </div>
  )
}

function CopyMarkdownButton({ markdown }) {
  const [copied, setCopied] = useState(false)

  if (!markdown) {
    return null
  }

  const copy = async () => {
    const ok = await copyText(markdown)
    setCopied(ok)
  }

  return (
    <button type="button" className={BUTTON} onClick={copy}>
      {copied ? <CheckIcon className="tw:h-3.5 tw:w-3.5" /> : <CopyIcon className="tw:h-3.5 tw:w-3.5" />}
      {copied ? 'Copied' : 'Copy Markdown'}
    </button>
  )
}

function EventBar({ event, navigation, issue, endpoints }) {
  if (!event) {
    return (
      <div className={cx(CARD, 'tw:p-4 tw:text-xs tw:text-[var(--wt-text-muted)]')}>
        No events stored for this issue.
      </div>
    )
  }

  const link = (id) => withQuery(withId(endpoints.issue, issue.id), { event: id })

  return (
    <div className={cx(CARD, 'tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:p-3')}>
      <span className="tw:font-mono tw:text-[11px] tw:text-[var(--wt-text-muted)]">
        {event.event_id ?? event.id}
      </span>
      {event.environment && <Chip>{event.environment}</Chip>}
      {event.release && <Chip>{event.release}</Chip>}
      {event.transaction && <Chip>{event.transaction}</Chip>}
      <span className="tw:text-[11px] tw:text-[var(--wt-text-muted)]">
        {formatDateTime(event.received_at)}
      </span>

      <div className="tw:ml-auto tw:flex tw:items-center tw:gap-2 tw:text-[11px]">
        <NavLink href={navigation?.prev ? link(navigation.prev) : null} label="Newer" back />
        <span className="tw:text-[var(--wt-text-faint)]">
          {navigation?.position ?? 0} / {navigation?.total ?? 0}
        </span>
        <NavLink href={navigation?.next ? link(navigation.next) : null} label="Older" />
      </div>
    </div>
  )
}

function NavLink({ href, label, back }) {
  if (!href) {
    return <span className="tw:text-[var(--wt-text-faint)]">{label}</span>
  }

  return (
    <a
      href={href}
      className="tw:flex tw:items-center tw:gap-1 tw:rounded tw:border tw:border-[var(--wt-border)] tw:px-2 tw:py-1 tw:hover:bg-[var(--wt-hover)]"
    >
      {back && <ChevronIcon className="tw:h-3 tw:w-3 tw:rotate-180" />}
      {label}
      {!back && <ChevronIcon className="tw:h-3 tw:w-3" />}
    </a>
  )
}

function TabBody({ tab, event }) {
  if (!event) {
    return <Empty>Nothing to show.</Empty>
  }
  if (tab === 'stacktrace') {
    return <Stacktrace event={event} />
  }
  if (tab === 'request') {
    return <PairTable pairs={flattenPairs(event.request)} empty="No request context." />
  }
  if (tab === 'breadcrumbs') {
    return <Breadcrumbs items={event.breadcrumbs} />
  }
  if (tab === 'user') {
    return <PairTable pairs={flattenPairs(event.user)} empty="No user attached." />
  }
  return <PairTable pairs={flattenPairs(event.tags)} empty="No tags." />
}

function Stacktrace({ event }) {
  const frames = useMemo(() => [...(event.stacktrace ?? [])].reverse(), [event])
  const [showVendor, setShowVendor] = useState(false)

  const appFrames = frames.filter((frame) => !isVendorFrame(frame))
  const visible = showVendor || appFrames.length === 0 ? frames : appFrames
  const hidden = frames.length - visible.length

  if (frames.length === 0) {
    return (
      <div className="tw:p-4">
        {event.exception?.value ? (
          <p className="tw:text-xs tw:text-[var(--wt-text)]">{event.exception.value}</p>
        ) : (
          <Empty>No stacktrace in this event.</Empty>
        )}
      </div>
    )
  }

  return (
    <div className="tw:flex tw:flex-col">
      {event.exception && (
        <div className="tw:border-b tw:border-[var(--wt-border)] tw:px-4 tw:py-3">
          <div className="tw:font-semibold tw:text-[var(--wt-text)]">{event.exception.type}</div>
          <div className="tw:mt-0.5 tw:text-xs tw:break-words tw:text-[var(--wt-text-muted)]">
            {event.exception.value}
          </div>
        </div>
      )}

      {hidden > 0 && (
        <button
          type="button"
          onClick={() => setShowVendor(true)}
          className="tw:border-b tw:border-[var(--wt-border)] tw:px-4 tw:py-2 tw:text-left tw:text-[11px] tw:text-[var(--wt-text-muted)] tw:hover:bg-[var(--wt-hover)]"
        >
          Show {hidden} vendor frame{hidden === 1 ? '' : 's'}
        </button>
      )}

      <ul className="tw:divide-y tw:divide-[var(--wt-border)]">
        {visible.map((frame, index) => (
          <Frame key={`${frame.filename ?? index}-${index}`} frame={frame} defaultOpen={index === 0} />
        ))}
      </ul>
    </div>
  )
}

function Frame({ frame, defaultOpen }) {
  const context = buildContext(frame)
  const [open, setOpen] = useState(defaultOpen && context.length > 0)
  const vendor = isVendorFrame(frame)
  const file = String(frame.filename ?? frame.abs_path ?? 'unknown')

  return (
    <li className={vendor ? 'tw:opacity-70' : undefined}>
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        disabled={context.length === 0}
        className="tw:flex tw:w-full tw:items-center tw:gap-2 tw:px-4 tw:py-2 tw:text-left tw:hover:bg-[var(--wt-hover)]"
      >
        {context.length > 0 && (
          <ChevronIcon
            className={cx(
              'tw:h-3 tw:w-3 tw:shrink-0 tw:text-[var(--wt-text-faint)]',
              open && 'tw:rotate-90',
            )}
          />
        )}
        <span
          className={cx(
            'wt-code tw:truncate',
            vendor ? 'tw:text-[var(--wt-text-muted)]' : 'tw:font-semibold tw:text-[var(--wt-text)]',
          )}
          title={file}
        >
          {shortenPath(file)}
        </span>
        {frame.lineno !== undefined && (
          <span className="wt-code tw:shrink-0 tw:text-[var(--wt-text-faint)]">:{frame.lineno}</span>
        )}
        {frame.function && (
          <span className="wt-code tw:ml-auto tw:truncate tw:text-[var(--wt-text-muted)]">
            {frame.function}
          </span>
        )}
      </button>

      {open && context.length > 0 && (
        <div className="wt-code tw:overflow-x-auto tw:bg-[var(--wt-code-bg)] tw:py-1">
          {context.map((line) => (
            <div
              key={line.number}
              className="tw:flex tw:gap-3 tw:px-4"
              style={line.current ? { background: 'var(--wt-code-highlight)' } : undefined}
            >
              <span className="tw:w-10 tw:shrink-0 tw:text-right tw:text-[var(--wt-text-faint)]">
                {line.number}
              </span>
              <span className="tw:whitespace-pre tw:text-[var(--wt-text)]">{line.text}</span>
            </div>
          ))}
        </div>
      )}
    </li>
  )
}

function buildContext(frame) {
  const lineno = Number(frame?.lineno ?? 0)
  const pre = Array.isArray(frame?.pre_context) ? frame.pre_context : []
  const post = Array.isArray(frame?.post_context) ? frame.post_context : []
  const current = typeof frame?.context_line === 'string' ? frame.context_line : null

  if (!current && pre.length === 0 && post.length === 0) {
    return []
  }

  const lines = []
  pre.forEach((text, index) =>
    lines.push({ number: lineno - pre.length + index, text, current: false }),
  )
  if (current !== null) {
    lines.push({ number: lineno, text: current, current: true })
  }
  post.forEach((text, index) => lines.push({ number: lineno + index + 1, text, current: false }))

  return lines
}

function Breadcrumbs({ items }) {
  const list = Array.isArray(items) ? items : []

  if (list.length === 0) {
    return <Empty>No breadcrumbs.</Empty>
  }

  return (
    <ul className="tw:divide-y tw:divide-[var(--wt-border)]">
      {list.map((crumb, index) => (
        <li key={index} className="tw:flex tw:items-start tw:gap-3 tw:px-4 tw:py-2">
          <span className="tw:w-24 tw:shrink-0 tw:text-[11px] tw:text-[var(--wt-text-faint)]">
            {crumb.category ?? crumb.type ?? '—'}
          </span>
          <div className="tw:min-w-0 tw:flex-1">
            <div className="tw:text-xs tw:break-words tw:text-[var(--wt-text)]">
              {stringify(crumb.message ?? crumb.data ?? crumb.type)}
            </div>
            {crumb.level && (
              <div className="tw:mt-0.5">
                <LevelBadge level={crumb.level} />
              </div>
            )}
          </div>
          <span className="tw:shrink-0 tw:text-[11px] tw:text-[var(--wt-text-faint)]">
            {crumb.timestamp ? formatDateTime(toDate(crumb.timestamp)) : ''}
          </span>
        </li>
      ))}
    </ul>
  )
}

function toDate(timestamp) {
  return typeof timestamp === 'number' ? new Date(timestamp * 1000).toISOString() : timestamp
}

function PairTable({ pairs, empty }) {
  if (!pairs || pairs.length === 0) {
    return <Empty>{empty}</Empty>
  }

  return (
    <table className="tw:w-full tw:table-fixed">
      <tbody className="tw:divide-y tw:divide-[var(--wt-border)]">
        {pairs.map(([key, value]) => (
          <tr key={key}>
            <td className="wt-code tw:w-56 tw:px-4 tw:py-1.5 tw:align-top tw:text-[var(--wt-text-muted)]">
              {key}
            </td>
            <td className="wt-code tw:px-4 tw:py-1.5 tw:break-all tw:text-[var(--wt-text)]">
              {value}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function EventSidebar({ events, event, issue, endpoints }) {
  const list = Array.isArray(events) ? events : []

  return (
    <aside className={cx(CARD, 'tw:h-fit tw:overflow-hidden')}>
      <h2 className="tw:border-b tw:border-[var(--wt-border)] tw:px-3 tw:py-2 tw:text-[11px] tw:font-semibold tw:tracking-wide tw:text-[var(--wt-text-muted)] tw:uppercase">
        Recent events
      </h2>
      {list.length === 0 ? (
        <Empty>None yet.</Empty>
      ) : (
        <ul className="tw:max-h-[70vh] tw:divide-y tw:divide-[var(--wt-border)] tw:overflow-y-auto">
          {list.map((item) => (
            <li key={item.id}>
              <a
                href={withQuery(withId(endpoints.issue, issue.id), { event: item.id })}
                className={cx(
                  'tw:block tw:px-3 tw:py-2 tw:hover:bg-[var(--wt-hover)]',
                  item.id === event?.id && 'tw:bg-[var(--wt-accent-soft)]',
                )}
              >
                <div className="tw:text-[11px] tw:text-[var(--wt-text)]">
                  {relativeTime(item.received_at)}
                </div>
                <div className="tw:mt-0.5 tw:flex tw:flex-wrap tw:gap-1 tw:text-[10px] tw:text-[var(--wt-text-faint)]">
                  {item.environment && <span>{item.environment}</span>}
                  {item.release && <span>· {item.release}</span>}
                </div>
              </a>
            </li>
          ))}
        </ul>
      )}
    </aside>
  )
}

function Empty({ children }) {
  return (
    <p className="tw:px-4 tw:py-8 tw:text-center tw:text-xs tw:text-[var(--wt-text-muted)]">
      {children}
    </p>
  )
}
