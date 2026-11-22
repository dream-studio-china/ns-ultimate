export const STORE_MEMBER_ROLES = Object.freeze(['owner', 'manager', 'clerk', 'fulfillment'])

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export function isUuid(value) {
  return typeof value === 'string' && UUID_PATTERN.test(value)
}

export function storeMembersPath(apiPrefix, storeUuid) {
  if (!isUuid(storeUuid)) throw new Error('store_member_store_uuid_invalid')
  return `${String(apiPrefix).replace(/\/+$/, '')}/stores/${storeUuid}/members`
}

export function storeMemberPayload(userUuid, role) {
  const normalizedUserUuid = typeof userUuid === 'string' ? userUuid.trim() : ''
  const normalizedRole = typeof role === 'string' ? role.trim().toLowerCase() : ''
  if (!isUuid(normalizedUserUuid)) throw new Error('store_member_user_uuid_invalid')
  if (!STORE_MEMBER_ROLES.includes(normalizedRole)) throw new Error('store_member_role_invalid')
  return { userUuid: normalizedUserUuid, role: normalizedRole }
}

export function memberRows(members, users) {
  if (!Array.isArray(members)) throw new Error('store_member_collection_invalid')
  if (!Array.isArray(users)) throw new Error('store_member_users_invalid')

  const accounts = new Map()
  for (const user of users) {
    if (!isUuid(user?.uuid)) continue
    const label = String(user.username || user.__toString || user.uuid).trim() || user.uuid
    accounts.set(user.uuid.toLowerCase(), label)
  }

  return members
    .filter(member => member && isUuid(member.userUuid) && STORE_MEMBER_ROLES.includes(member.role))
    .map(member => ({
      ...member,
      accountLabel: accounts.get(member.userUuid.toLowerCase()) || member.userUuid
    }))
    .sort((left, right) => left.accountLabel.localeCompare(right.accountLabel)
      || left.userUuid.localeCompare(right.userUuid))
}

export function filterMemberRows(rows, query) {
  if (!Array.isArray(rows)) throw new Error('store_member_rows_invalid')
  const needle = String(query || '').trim().toLocaleLowerCase()
  if (!needle) return rows

  return rows.filter(row => [
    row.accountLabel,
    row.userUuid,
    row.role,
    row.status
  ].some(value => String(value || '').toLocaleLowerCase().includes(needle)))
}
