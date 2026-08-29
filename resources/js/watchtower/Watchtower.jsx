import { AlertRules } from './AlertRules'
import { BellIcon, CogIcon, ListIcon, ShieldIcon } from './icons'
import { IssueDetail } from './IssueDetail'
import { IssuesList } from './IssuesList'
import { cx } from './lib'
import { SettingsProjects } from './SettingsProjects'
import './watchtower.css'

/**
 * Watchtower — the embedded issue tracker UI.
 *
 * One Inertia component serves every screen; the `view` prop decides which.
 * Everything Laravel-shaped arrives as props: endpoint URLs, brand, data. The
 * module itself knows no routes and is re-themable through the `--wt-*` tokens
 * in watchtower.css.
 *
 * @param {'issues'|'issue'|'alerts'|'settings'} view which screen to render.
 * @param {Record<string, string>} endpoints absolute URLs; id-bearing ones carry
 *   a `__ID__` placeholder resolved by `withId()`.
 * @param {{name?: string, version?: string}} [brand]
 * @param {string} [csrfToken] sent with every write request.
 */
export function Watchtower(props) {
  const { view, endpoints, brand } = props

  return (
    <div className="wt-root tw:min-h-screen tw:text-sm">
      <TopBar view={view} endpoints={endpoints} brand={brand} />
      <main className="tw:mx-auto tw:w-full tw:max-w-7xl tw:px-4 tw:py-5">
        <Screen {...props} />
      </main>
    </div>
  )
}

function Screen(props) {
  if (props.view === 'issue') {
    return <IssueDetail {...props} />
  }
  if (props.view === 'alerts') {
    return <AlertRules {...props} />
  }
  if (props.view === 'settings') {
    return <SettingsProjects {...props} />
  }
  return <IssuesList {...props} />
}

const NAV = [
  { key: 'issues', label: 'Issues', endpoint: 'issues', Icon: ListIcon, matches: ['issues', 'issue'] },
  { key: 'alerts', label: 'Alerts', endpoint: 'alerts', Icon: BellIcon, matches: ['alerts'] },
  { key: 'settings', label: 'Settings', endpoint: 'settings', Icon: CogIcon, matches: ['settings'] },
]

function TopBar({ view, endpoints, brand }) {
  return (
    <header className="tw:sticky tw:top-0 tw:z-20 tw:border-b tw:border-[var(--wt-border)] tw:bg-[var(--wt-toolbar)]">
      <div className="tw:mx-auto tw:flex tw:w-full tw:max-w-7xl tw:items-center tw:gap-4 tw:px-4 tw:py-2.5">
        <a
          href={endpoints?.issues ?? '#'}
          className="tw:flex tw:items-center tw:gap-2 tw:text-[var(--wt-text)]"
        >
          <span className="tw:flex tw:h-6 tw:w-6 tw:items-center tw:justify-center tw:rounded tw:bg-[var(--wt-accent)] tw:text-[var(--wt-accent-foreground)]">
            <ShieldIcon className="tw:h-3.5 tw:w-3.5" />
          </span>
          <span className="tw:text-sm tw:font-semibold">Watchtower</span>
        </a>

        <nav className="tw:flex tw:items-center tw:gap-1">
          {NAV.map(({ key, label, endpoint, Icon, matches }) => (
            <a
              key={key}
              href={endpoints?.[endpoint] ?? '#'}
              className={cx(
                'tw:flex tw:items-center tw:gap-1.5 tw:rounded-md tw:px-2.5 tw:py-1.5 tw:text-xs tw:font-medium',
                matches.includes(view)
                  ? 'tw:bg-[var(--wt-accent-soft)] tw:text-[var(--wt-accent)]'
                  : 'tw:text-[var(--wt-text-muted)] tw:hover:bg-[var(--wt-hover)]',
              )}
            >
              <Icon className="tw:h-3.5 tw:w-3.5" />
              {label}
            </a>
          ))}
        </nav>

        <div className="tw:ml-auto tw:flex tw:items-center tw:gap-2 tw:text-[11px] tw:text-[var(--wt-text-faint)]">
          {brand?.name && <span className="tw:truncate">{brand.name}</span>}
          {brand?.version && <span>v{brand.version}</span>}
        </div>
      </div>
    </header>
  )
}
