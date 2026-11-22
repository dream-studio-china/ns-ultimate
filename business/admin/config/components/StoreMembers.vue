<template>
  <section class="store-members" aria-labelledby="store-members-title">
    <header class="store-members__header">
      <div>
        <span class="store-members__eyebrow">{{ $t('Store Management') }}</span>
        <h1 id="store-members-title">{{ $t('Store members') }}</h1>
        <p>{{ $t('Store member management help') }}</p>
      </div>
      <el-button :loading="loading" icon="el-icon-refresh" @click="load">{{ $t('Refresh') }}</el-button>
    </header>

    <el-alert
      :title="$t('Store membership permission help')"
      type="warning"
      :closable="false"
      show-icon
      class="store-members__notice"
    />
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      show-icon
      class="store-members__notice"
    />

    <div class="store-members__toolbar">
      <div class="store-members__filters">
        <el-select
          v-model="selectedStoreUuid"
          class="store-members__store"
          filterable
          :aria-label="$t('Store')"
          :placeholder="$t('Select store')"
          :disabled="loading || !stores.length"
          @change="loadMembers"
        >
          <el-option
            v-for="store in stores"
            :key="store.uuid"
            :value="store.uuid"
            :label="storeLabel(store)"
          />
        </el-select>
        <el-input
          v-model="query"
          class="store-members__search"
          clearable
          prefix-icon="el-icon-search"
          :aria-label="$t('Search members')"
          :placeholder="$t('Search members')"
        />
      </div>
      <el-button
        type="primary"
        icon="el-icon-plus"
        :disabled="!selectedStoreUuid"
        @click="openCreate"
      >
        {{ $t('Add or update member') }}
      </el-button>
    </div>

    <div v-loading="loading" class="store-members__table-wrap">
      <el-table :data="filteredMembers" :empty-text="emptyText" row-key="uuid">
        <el-table-column :label="$t('Account')" min-width="210">
          <template #default="{ row }">
            <router-link :to="userDetailPath(row.userUuid)" class="store-members__account">
              {{ row.accountLabel }}
            </router-link>
          </template>
        </el-table-column>
        <el-table-column :label="$t('User UUID')" min-width="310" show-overflow-tooltip>
          <template #default="{ row }"><code>{{ row.userUuid }}</code></template>
        </el-table-column>
        <el-table-column :label="$t('Membership role')" min-width="130">
          <template #default="{ row }">
            <el-tag effect="plain">{{ $t(memberRoleLabel(row.role)) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column :label="$t('Status')" min-width="120">
          <template #default="{ row }">
            <el-tag :type="memberStatusType(row.status)" effect="plain">
              {{ $t(memberStatusLabel(row.status)) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column :label="$t('Created at')" min-width="180">
          <template #default="{ row }">{{ formatDate(row.createdAt) }}</template>
        </el-table-column>
        <el-table-column :label="$t('Updated at')" min-width="180">
          <template #default="{ row }">{{ formatDate(row.updatedAt) }}</template>
        </el-table-column>
        <el-table-column :label="$t('Actions')" width="110" fixed="right">
          <template #default="{ row }">
            <el-button type="text" @click="openEdit(row)">{{ $t('Edit') }}</el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <el-dialog
      v-model="dialogVisible"
      :title="editingMember ? $t('Update store member') : $t('Add store member')"
      width="min(560px, 94vw)"
      append-to-body
      :close-on-click-modal="!saving"
      :close-on-press-escape="!saving"
      :show-close="!saving"
      destroy-on-close
      @closed="resetForm"
    >
      <el-alert
        v-if="formError"
        :title="formError"
        type="error"
        :closable="false"
        show-icon
        class="store-members__form-error"
      />
      <el-alert
        :title="$t('Store member update help')"
        type="info"
        :closable="false"
        show-icon
        class="store-members__form-error"
      />
      <el-form label-position="top" @submit.prevent>
        <el-form-item :label="$t('Account')" required>
          <el-select
            v-model="form.userUuid"
            filterable
            class="store-members__form-field"
            :disabled="saving || Boolean(editingMember)"
            :placeholder="$t('Select user')"
          >
            <el-option
              v-for="user in users"
              :key="user.uuid"
              :value="user.uuid"
              :label="userLabel(user)"
            />
          </el-select>
          <p v-if="!users.length" class="store-members__empty-hint">{{ $t('No users available') }}</p>
        </el-form-item>
        <el-form-item :label="$t('Membership role')" required>
          <el-select
            v-model="form.role"
            class="store-members__form-field"
            :disabled="saving"
            :placeholder="$t('Select membership role')"
          >
            <el-option
              v-for="role in roles"
              :key="role"
              :value="role"
              :label="$t(memberRoleLabel(role))"
            />
          </el-select>
        </el-form-item>
        <p class="store-members__selected-store">
          {{ $t('Store') }}: <strong>{{ selectedStoreLabel }}</strong>
        </p>
      </el-form>
      <template #footer>
        <el-button :disabled="saving" @click="dialogVisible = false">{{ $t('Cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submit">{{ $t('Save') }}</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<script>
import { createUiFeedback } from '@/easyadmin/ui/vue/feedback'
import { API_PREFIX, apiPath } from '@/api/prefix'
import request from '@/utils/request'
import { fetchAllPages } from './catalog-copy.mjs'
import {
  STORE_MEMBER_ROLES,
  filterMemberRows,
  isUuid,
  memberRows,
  storeMemberPayload,
  storeMembersPath
} from './store-members.mjs'

const storesEndpoint = apiPath(API_PREFIX, 'manage/stores')
const usersEndpoint = apiPath(API_PREFIX, 'manage/users')

export default {
  name: 'StoreMembers',
  data() {
    return {
      stores: [],
      users: [],
      members: [],
      selectedStoreUuid: '',
      query: '',
      roles: STORE_MEMBER_ROLES,
      loading: false,
      saving: false,
      dialogVisible: false,
      editingMember: null,
      error: '',
      formError: '',
      form: {
        userUuid: '',
        role: 'manager'
      }
    }
  },
  computed: {
    rows() {
      return memberRows(this.members, this.users)
    },
    filteredMembers() {
      return filterMemberRows(this.rows, this.query)
    },
    selectedStore() {
      return this.stores.find(store => store.uuid === this.selectedStoreUuid) || null
    },
    selectedStoreLabel() {
      return this.selectedStore ? this.storeLabel(this.selectedStore) : ''
    },
    emptyText() {
      if (!this.selectedStoreUuid) return this.$t('Select a store to manage members')
      if (this.query.trim()) return this.$t('No matching store members')
      return this.$t('No store members')
    }
  },
  mounted() {
    this.load()
  },
  methods: {
    async load() {
      if (this.loading) return
      this.loading = true
      this.error = ''
      try {
        const [stores, users] = await Promise.all([
          fetchAllPages(request.get, storesEndpoint, { '@display': 'reduce', '@order': 'entity.name|ASC' }),
          fetchAllPages(request.get, usersEndpoint, { '@display': 'reduce', '@order': 'entity.username|ASC' })
        ])
        this.stores = stores.filter(store => isUuid(store?.uuid))
        this.users = users.filter(user => isUuid(user?.uuid))
        if (!this.stores.some(store => store.uuid === this.selectedStoreUuid)) {
          this.selectedStoreUuid = this.stores[0]?.uuid || ''
        }
        await this.loadMembers()
      } catch (cause) {
        this.error = this.failureMessage(cause, 'Failed to load store members')
      } finally {
        this.loading = false
      }
    },
    async loadMembers() {
      this.members = []
      this.query = ''
      if (!isUuid(this.selectedStoreUuid)) return
      this.loading = true
      this.error = ''
      try {
        this.members = await fetchAllPages(request.get, storeMembersPath(API_PREFIX, this.selectedStoreUuid))
      } catch (cause) {
        this.error = this.failureMessage(cause, 'Failed to load store members')
      } finally {
        this.loading = false
      }
    },
    openCreate() {
      if (!this.selectedStoreUuid) return
      this.editingMember = null
      this.form = { userUuid: '', role: 'manager' }
      this.formError = ''
      this.dialogVisible = true
    },
    openEdit(member) {
      this.editingMember = member
      this.form = { userUuid: member.userUuid, role: member.role }
      this.formError = ''
      this.dialogVisible = true
    },
    async submit() {
      if (this.saving) return
      this.formError = ''
      let payload
      try {
        payload = storeMemberPayload(this.form.userUuid, this.form.role)
      } catch {
        this.formError = this.$t('Select a valid account and membership role')
        return
      }

      this.saving = true
      try {
        await request.post(storeMembersPath(API_PREFIX, this.selectedStoreUuid), payload)
        createUiFeedback().success(this.$t('Store member saved'))
        this.dialogVisible = false
        await this.loadMembers()
      } catch (cause) {
        this.formError = this.failureMessage(cause, 'Failed to save store member')
      } finally {
        this.saving = false
      }
    },
    resetForm() {
      if (this.saving) return
      this.editingMember = null
      this.formError = ''
      this.form = { userUuid: '', role: 'manager' }
    },
    storeLabel(store) {
      const code = String(store?.code || '').trim()
      const name = String(store?.name || store?.__toString || store?.uuid || '').trim()
      return code && name ? `${name} · ${code}` : name || code || store?.uuid || ''
    },
    userLabel(user) {
      return String(user?.username || user?.__toString || user?.uuid || '').trim()
    },
    userDetailPath(userUuid) {
      return `/user/${userUuid}/detail`
    },
    memberRoleLabel(role) {
      return {
        owner: 'Store member role owner',
        manager: 'Store member role manager',
        clerk: 'Store member role clerk',
        fulfillment: 'Store member role fulfillment'
      }[role] || role
    },
    memberStatusLabel(status) {
      return {
        active: 'Membership active',
        suspended: 'Membership suspended',
        revoked: 'Membership revoked'
      }[status] || status
    },
    memberStatusType(status) {
      return { active: 'success', suspended: 'warning', revoked: 'danger' }[status] || 'info'
    },
    formatDate(value) {
      if (!value) return '—'
      const date = new Date(value)
      return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
    },
    failureMessage(cause, fallbackKey) {
      return cause?.response?.data?.message ||
        cause?.response?.data?.error ||
        cause?.message ||
        this.$t(fallbackKey)
    }
  }
}
</script>

<style scoped>
.store-members { max-width: 1540px; margin: 0 auto; padding: 32px; color: var(--text-primary); }
.store-members__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; margin-bottom: 24px; }
.store-members__eyebrow { color: var(--accent); font-size: 12px; font-weight: 700; letter-spacing: .08em; }
.store-members h1 { margin: 9px 0 8px; font-size: 28px; letter-spacing: -.03em; }
.store-members p { max-width: 820px; margin: 0; color: var(--text-secondary); font-size: 13px; line-height: 1.75; }
.store-members__notice { margin-bottom: 16px; }
.store-members__toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 14px; }
.store-members__filters { display: flex; align-items: center; gap: 12px; }
.store-members__store { width: 300px; }
.store-members__search { width: 260px; }
.store-members__table-wrap { min-height: 180px; overflow: hidden; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); }
.store-members__account { color: var(--accent); font-weight: 600; text-decoration: none; }
.store-members__account:hover { text-decoration: underline; }
.store-members__form-error { margin-bottom: 16px; }
.store-members__form-field { width: 100%; }
.store-members__empty-hint { margin-top: 6px; font-size: 12px; }
.store-members__selected-store { margin-top: 4px; }
@media (max-width: 760px) {
  .store-members { padding: 20px 14px; }
  .store-members__header, .store-members__toolbar, .store-members__filters { align-items: stretch; flex-direction: column; }
  .store-members__store, .store-members__search { width: 100%; }
}
</style>
