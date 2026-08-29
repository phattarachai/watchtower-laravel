/**
 * Self-contained helpers for the Watchtower UI module — no external deps, so the
 * whole folder can be lifted into another project as-is.
 */

/** Tiny clsx-lite: join truthy strings / object keys. Avoids a runtime dep. */
export function cx(...args) {
  const out = []
  for (const a of args) {
    if (!a) {
      continue
    }
    if (typeof a === 'string') {
      out.push(a)
    } else if (Array.isArray(a)) {
      out.push(cx(...a))
    } else if (typeof a === 'object') {
      for (const [k, v] of Object.entries(a)) {
        if (v) {
          out.push(k)
        }
      }
    }
  }
  return out.join(' ')
}

/** The server hands out endpoint templates carrying this stand-in for an id. */
export const ID_PLACEHOLDER = '__ID__'

/** Resolve an endpoint template against a concrete id. */
export function withId(template, id) {
  return String(template ?? '').replaceAll(ID_PLACEHOLDER, encodeURIComponent(String(id)))
}

/** Append query params to an endpoint URL, skipping empty values. */
export function withQuery(url, params) {
  const search = new URLSearchParams(
    Object.entries(params ?? {}).filter(
      ([, value]) => value !== null && value !== undefined && value !== '',
    ),
  )
  return search.size === 0 ? url : `${url}${url.includes('?') ? '&' : '?'}${search}`
}

/**
 * One JSON request to a Watchtower endpoint. Never throws on a non-2xx — the
 * caller branches on `status` (422 carries a `message` or Laravel `errors`).
 *
 * @returns {Promise<{ok: boolean, status: number, data: Record<string, any>}>}
 */
export async function sendJson(url, method, csrfToken, body) {
  const response = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...csrfHeader(csrfToken),
      'X-Requested-With': 'XMLHttpRequest',
    },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  })

  const data = await response.json().catch(() => ({}))

  return { ok: response.ok, status: response.status, data }
}

function csrfHeader(csrfToken) {
  if (csrfToken) {
    return { 'X-CSRF-TOKEN': csrfToken }
  }

  const cookie = document.cookie
    .split('; ')
    .find((entry) => entry.startsWith('XSRF-TOKEN='))

  if (!cookie) {
    return {}
  }

  return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) }
}

/** Flatten a Laravel 422 body into a single readable line. */
export function firstError(data) {
  if (data?.message && !data?.errors) {
    return data.message
  }
  const errors = data?.errors ?? {}
  const first = Object.values(errors)[0]
  return Array.isArray(first) ? first[0] : (data?.message ?? 'Something went wrong.')
}

const UNITS = [
  ['y', 31536000],
  ['mo', 2592000],
  ['d', 86400],
  ['h', 3600],
  ['m', 60],
  ['s', 1],
]

/** "3h ago" / "just now" — compact enough for a dense issue table. */
export function relativeTime(value) {
  if (!value) {
    return '—'
  }
  const then = new Date(value).getTime()
  if (Number.isNaN(then)) {
    return '—'
  }
  const seconds = Math.max(0, Math.round((Date.now() - then) / 1000))
  if (seconds < 5) {
    return 'just now'
  }
  for (const [suffix, size] of UNITS) {
    if (seconds >= size) {
      return `${Math.floor(seconds / size)}${suffix} ago`
    }
  }
  return 'just now'
}

/** Absolute local timestamp for tooltips and detail headers. */
export function formatDateTime(value) {
  if (!value) {
    return '—'
  }
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString()
}

/** The `--wt-level-*` token a severity maps onto. */
export function levelToken(level) {
  const known = ['debug', 'info', 'warning', 'error', 'fatal']
  return known.includes(String(level ?? '').toLowerCase()) ? String(level).toLowerCase() : 'error'
}

/** Strip the leading path noise so a frame reads as its own file. */
export function shortenPath(path) {
  const value = String(path ?? '')
  const parts = value.split('/')
  return parts.length <= 3 ? value : `…/${parts.slice(-3).join('/')}`
}

/** A frame that lives under vendor/node_modules is collapsed by default. */
export function isVendorFrame(frame) {
  if (frame?.in_app === true) {
    return false
  }
  if (frame?.in_app === false) {
    return true
  }
  const file = String(frame?.filename ?? frame?.abs_path ?? '')
  return /\/(vendor|node_modules)\//.test(file)
}

/** Copy text to the clipboard, resolving to whether it worked. */
export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    return false
  }
}

/** Render any payload leaf as one readable line. */
export function stringify(value) {
  if (value === null || value === undefined) {
    return '—'
  }
  if (typeof value === 'string') {
    return value
  }
  if (typeof value === 'object') {
    try {
      return JSON.stringify(value)
    } catch {
      return String(value)
    }
  }
  return String(value)
}

/** Flatten a nested payload block into sorted `key → line` pairs. */
export function flattenPairs(source, prefix = '') {
  if (!source || typeof source !== 'object') {
    return []
  }
  const pairs = []
  for (const [key, value] of Object.entries(source)) {
    const label = prefix ? `${prefix}.${key}` : key
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      pairs.push(...flattenPairs(value, label))
    } else {
      pairs.push([label, stringify(value)])
    }
  }
  return pairs
}
