<template>
    <form autocomplete="off" @submit.prevent="submit">
        <div class="system-form-grid system-form-single">
            <div class="system-field">
                <strong>Proveedor 1 · apiperu.dev</strong>
            </div>
            <div class="system-field" :class="{'has-danger': errors.url_apiruc}">
                <label for="ruc-url">URL</label>
                <el-input id="ruc-url" class="system-mono-input" v-model="form.url_apiruc" placeholder="https://"></el-input>
                <small class="form-control-feedback" v-if="errors.url_apiruc" v-text="errors.url_apiruc[0]"></small>
            </div>
            <div class="system-field" :class="{'has-danger': errors.token_apiruc}">
                <label for="ruc-token">Token</label>
                <el-input id="ruc-token" class="system-mono-input" :type="reveal ? 'text' : 'password'" v-model="form.token_apiruc" autocomplete="off">
                    <button slot="suffix" type="button" class="system-input-toggle" aria-label="Mostrar u ocultar token" :aria-pressed="String(reveal)" @click="reveal = !reveal">
                        <svg class="sys-icon" aria-hidden="true"><use :href="$icon((reveal ? 'eye-off' : 'eye'))" /></svg>
                    </button>
                </el-input>
                <small class="form-control-feedback" v-if="errors.token_apiruc" v-text="errors.token_apiruc[0]"></small>
            </div>
            <div class="system-field">
                <strong>Proveedor 2 · apiperu.pe</strong>
            </div>
            <div class="system-field" :class="{'has-danger': errors.secondary_url_apiruc}">
                <label for="ruc-secondary-url">URL</label>
                <el-input id="ruc-secondary-url" class="system-mono-input" v-model="form.secondary_url_apiruc" placeholder="https://api.apiperu.pe"></el-input>
                <small class="form-control-feedback" v-if="errors.secondary_url_apiruc" v-text="errors.secondary_url_apiruc[0]"></small>
            </div>
            <div class="system-field" :class="{'has-danger': errors.secondary_token_apiruc}">
                <label for="ruc-secondary-token">Token</label>
                <el-input id="ruc-secondary-token" class="system-mono-input" :type="reveal_secondary ? 'text' : 'password'" v-model="form.secondary_token_apiruc" autocomplete="off">
                    <button slot="suffix" type="button" class="system-input-toggle" aria-label="Mostrar u ocultar token del proveedor 2" :aria-pressed="String(reveal_secondary)" @click="reveal_secondary = !reveal_secondary">
                        <svg class="sys-icon" aria-hidden="true"><use :href="$icon((reveal_secondary ? 'eye-off' : 'eye'))" /></svg>
                    </button>
                </el-input>
                <small class="form-control-feedback" v-if="errors.secondary_token_apiruc" v-text="errors.secondary_token_apiruc[0]"></small>
            </div>
            <div class="system-field" :class="{'has-danger': errors.apiruc_primary}">
                <label for="ruc-primary">Proveedor principal</label>
                <el-select id="ruc-primary" v-model="form.apiruc_primary">
                    <el-option value="primary" label="Proveedor 1 · apiperu.dev"></el-option>
                    <el-option value="secondary" label="Proveedor 2 · apiperu.pe"></el-option>
                </el-select>
                <small class="form-control-feedback" v-if="errors.apiruc_primary" v-text="errors.apiruc_primary[0]"></small>
                <small>Si el principal falla (sin saldo, token inválido o sin conexión), se consulta automáticamente el otro.</small>
            </div>
            <div class="system-form-actions">
                <button type="submit" class="system-button system-button-primary" :disabled="loading_submit">{{ loading_submit ? 'Guardando…' : 'Guardar' }}</button>
            </div>
        </div>
    </form>
</template>

<script>
export default {
  data() {
    return {
      loading_submit: false,
      reveal: false,
      reveal_secondary: false,
      // headers: headers_token,
      resource: "configurations",
      errors: {},
      form: {},
    };
  },
  async created() {
    await this.initForm();

    await this.$http.get(`/${this.resource}/apiruc`).then(response => {
        this.form.url_apiruc = response.data.url_apiruc;
        this.form.token_apiruc = response.data.token_apiruc;
        this.form.secondary_url_apiruc = response.data.secondary_url_apiruc;
        this.form.secondary_token_apiruc = response.data.secondary_token_apiruc;
        this.form.apiruc_primary = response.data.apiruc_primary || 'primary';

        // Legacy "internal lookup" marker: show the token field empty instead.
        if (this.form.token_apiruc == 'false') {
          this.form.token_apiruc = null;
        }
        this.emitStatus();
    });
  },
  methods: {
    emitStatus() {
      this.$emit("status", {
        ruc: !!(this.form.url_apiruc && this.form.token_apiruc) || !!this.form.secondary_token_apiruc,
        ruc_partial: !!(this.form.url_apiruc || this.form.token_apiruc || this.form.secondary_url_apiruc || this.form.secondary_token_apiruc)
      });
    },
    initForm() {
      this.errors = {};
      this.form = {
        url_apiruc: null,
        token_apiruc: null,
        secondary_url_apiruc: null,
        secondary_token_apiruc: null,
        apiruc_primary: 'primary'
      };
    },
    submit() {
      this.loading_submit = true;
      const payload = Object.assign({}, this.form);
      this.$http
        .post(`/${this.resource}`, payload)
        .then(response => {
          if (response.data.success) {
            this.$message.success(response.data.message);
            this.emitStatus();
          } else {
            this.$message.error(response.data.message);
          }
        })
        .catch(error => {
          if (error.response.status === 422) {
            this.errors = error.response.data;
          } else {
            console.log(error);
          }
        })
        .then(() => {
          this.loading_submit = false;
        });
    },
  }
};
</script>

