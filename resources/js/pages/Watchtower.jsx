import { Head } from '@inertiajs/react'

import { Watchtower } from '@watchtower'

import '../../css/watchtower.css'

/**
 * The only file the Watchtower package publishes into the host app —
 * `app.jsx`'s import.meta.glob never leaves ./pages, so the page must live here
 * while the module itself is reached through the `@watchtower` Vite alias.
 *
 * Everything Laravel-shaped stops at this file: endpoint URLs arrive as props
 * (no route helpers), and the CSRF token is read from the meta tag.
 */
export default function WatchtowerPage(props) {
  const csrfToken =
    document.querySelector('meta[name=csrf-token]')?.getAttribute('content') ?? ''

  return (
    <>
      <Head title={titleFor(props.view)} />
      <Watchtower {...props} csrfToken={csrfToken} />
    </>
  )
}

function titleFor(view) {
  if (view === 'issue') {
    return 'Issue · Watchtower'
  }
  if (view === 'alerts') {
    return 'Alerts · Watchtower'
  }
  if (view === 'settings') {
    return 'Settings · Watchtower'
  }
  return 'Issues · Watchtower'
}
