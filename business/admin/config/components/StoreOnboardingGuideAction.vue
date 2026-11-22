<template>
  <span class="store-onboarding-guide-action">
    <el-button
      size="default"
      type="primary"
      plain
      icon="el-icon-document"
      @click="visible = true"
    >
      {{ $t('Store onboarding guide') }}
    </el-button>

    <el-dialog
      v-model="visible"
      class="store-onboarding-guide-dialog"
      :title="$t('Store onboarding guide')"
      width="min(820px, 94vw)"
      append-to-body
      align-center
      destroy-on-close
    >
      <el-alert
        :title="$t('Store onboarding order help')"
        type="info"
        :closable="false"
        show-icon
        class="store-onboarding-guide__notice"
      />
      <el-alert
        :title="$t('Store onboarding permission help')"
        type="warning"
        :closable="false"
        show-icon
        class="store-onboarding-guide__notice"
      />

      <ol class="store-onboarding-guide__steps">
        <li
          v-for="(step, index) in steps"
          :key="step.key"
          class="store-onboarding-guide__step"
        >
          <span class="store-onboarding-guide__number" aria-hidden="true">{{ index + 1 }}</span>
          <div>
            <h3>{{ $t(step.titleKey) }}</h3>
            <p>{{ $t(step.descriptionKey) }}</p>
          </div>
        </li>
      </ol>

      <template #footer>
        <el-button type="primary" @click="visible = false">{{ $t('Close') }}</el-button>
      </template>
    </el-dialog>
  </span>
</template>

<script>
import { STORE_ONBOARDING_STEPS } from './store-onboarding-guide.mjs'

export default {
  name: 'StoreOnboardingGuideAction',
  data() {
    return {
      visible: false,
      steps: STORE_ONBOARDING_STEPS
    }
  }
}
</script>

<style scoped>
.store-onboarding-guide-action {
  order: 2;
  display: inline-flex;
}

.store-onboarding-guide__notice + .store-onboarding-guide__notice {
  margin-top: 10px;
}

.store-onboarding-guide__steps {
  display: grid;
  gap: 12px;
  margin: 20px 0 0;
  padding: 0;
  list-style: none;
}

.store-onboarding-guide__step {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr);
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
  background: var(--el-fill-color-blank);
}

.store-onboarding-guide__number {
  display: inline-grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  color: #fff;
  background: var(--el-color-primary);
  font-weight: 700;
}

.store-onboarding-guide__step h3 {
  margin: 2px 0 5px;
  font-size: 15px;
}

.store-onboarding-guide__step p {
  margin: 0;
  color: var(--el-text-color-regular);
  line-height: 1.65;
}

:global(.store-onboarding-guide-dialog .el-dialog__body) {
  max-height: min(68vh, 680px);
  overflow-y: auto;
}

@media (max-width: 600px) {
  .store-onboarding-guide__step {
    grid-template-columns: 30px minmax(0, 1fr);
    padding: 12px;
  }

  .store-onboarding-guide__number {
    width: 28px;
    height: 28px;
  }
}
</style>
