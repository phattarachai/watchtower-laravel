const base = {
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.8,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
  'aria-hidden': 'true',
}

export function ShieldIcon(props) {
  return (
    <svg {...base} {...props}>
      <path d="M12 3l7 3v6c0 4.4-3 8.2-7 9-4-.8-7-4.6-7-9V6l7-3z" />
    </svg>
  )
}

export function BellIcon(props) {
  return (
    <svg {...base} {...props}>
      <path d="M18 16V11a6 6 0 10-12 0v5l-2 3h16l-2-3z" />
      <path d="M10 21h4" />
    </svg>
  )
}

export function CogIcon(props) {
  return (
    <svg {...base} {...props}>
      <circle cx="12" cy="12" r="3" />
      <path d="M12 3v2m0 14v2M3 12h2m14 0h2M5.6 5.6l1.4 1.4m10 10l1.4 1.4m0-12.8l-1.4 1.4m-10 10l-1.4 1.4" />
    </svg>
  )
}

export function ListIcon(props) {
  return (
    <svg {...base} {...props}>
      <path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" />
    </svg>
  )
}

export function ChevronIcon(props) {
  return (
    <svg {...base} {...props}>
      <path d="M9 6l6 6-6 6" />
    </svg>
  )
}

export function CopyIcon(props) {
  return (
    <svg {...base} {...props}>
      <rect x="9" y="9" width="11" height="11" rx="2" />
      <path d="M5 15V5a2 2 0 012-2h10" />
    </svg>
  )
}

export function RefreshIcon(props) {
  return (
    <svg {...base} {...props}>
      <path d="M20 11a8 8 0 10-2.3 6.3" />
      <path d="M20 5v6h-6" />
    </svg>
  )
}

export function CloseIcon(props) {
  return (
    <svg {...base} {...props}>
      <path d="M6 6l12 12M18 6L6 18" />
    </svg>
  )
}

export function CheckIcon(props) {
  return (
    <svg {...base} {...props}>
      <path d="M5 12.5l4.5 4.5L19 7.5" />
    </svg>
  )
}

export function SearchIcon(props) {
  return (
    <svg {...base} {...props}>
      <circle cx="11" cy="11" r="6" />
      <path d="M20 20l-3.5-3.5" />
    </svg>
  )
}

export function SendIcon(props) {
  return (
    <svg {...base} {...props}>
      <path d="M21 3L10.5 13.5M21 3l-6.5 18-4-8-8-4L21 3z" />
    </svg>
  )
}

export function TrashIcon(props) {
  return (
    <svg {...base} {...props}>
      <path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13" />
    </svg>
  )
}
