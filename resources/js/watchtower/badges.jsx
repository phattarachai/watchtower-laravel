import { cx, levelToken } from './lib'

export function LevelBadge({ level }) {
  const token = levelToken(level)

  return (
    <span
      className="tw:inline-flex tw:items-center tw:gap-1 tw:rounded tw:px-1.5 tw:py-0.5 tw:text-[10px] tw:font-semibold tw:uppercase tw:tracking-wide"
      style={{
        color: `var(--wt-level-${token})`,
        background: `color-mix(in srgb, var(--wt-level-${token}) 14%, transparent)`,
      }}
    >
      {token}
    </span>
  )
}

export function StatusPill({ status }) {
  const value = String(status ?? 'unresolved')

  return (
    <span
      className="tw:inline-flex tw:items-center tw:rounded-full tw:border tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-medium"
      style={{
        color: `var(--wt-status-${value}, var(--wt-text-muted))`,
        borderColor: `color-mix(in srgb, var(--wt-status-${value}, var(--wt-text-muted)) 40%, transparent)`,
      }}
    >
      {value}
    </span>
  )
}

export function Chip({ children, tone = 'muted', className }) {
  return (
    <span
      className={cx(
        'tw:inline-flex tw:items-center tw:gap-1 tw:rounded tw:border tw:border-[var(--wt-border)] tw:px-1.5 tw:py-0.5 tw:text-[11px]',
        tone === 'muted' ? 'tw:text-[var(--wt-text-muted)]' : 'tw:text-[var(--wt-text)]',
        className,
      )}
    >
      {children}
    </span>
  )
}

export const BUTTON =
  'tw:inline-flex tw:items-center tw:gap-1.5 tw:rounded-md tw:border tw:border-[var(--wt-border)] tw:bg-[var(--wt-raised)] tw:px-2.5 tw:py-1.5 tw:text-xs tw:font-medium tw:text-[var(--wt-text)] tw:hover:bg-[var(--wt-hover)] tw:disabled:opacity-50'

export const BUTTON_ACCENT =
  'tw:inline-flex tw:items-center tw:gap-1.5 tw:rounded-md tw:border tw:border-transparent tw:bg-[var(--wt-accent)] tw:px-2.5 tw:py-1.5 tw:text-xs tw:font-semibold tw:text-[var(--wt-accent-foreground)] tw:hover:opacity-90 tw:disabled:opacity-50'

export const INPUT =
  'tw:w-full tw:rounded-md tw:border tw:border-[var(--wt-border)] tw:bg-[var(--wt-bg)] tw:px-2.5 tw:py-1.5 tw:text-xs tw:text-[var(--wt-text)] tw:outline-none tw:focus:border-[var(--wt-accent)]'

export const LABEL =
  'tw:mb-1 tw:block tw:text-[11px] tw:font-medium tw:text-[var(--wt-text-muted)]'

export const CARD =
  'tw:rounded-lg tw:border tw:border-[var(--wt-border)] tw:bg-[var(--wt-raised)]'
