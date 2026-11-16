<template>
    <form autocomplete="off" @submit.prevent="submit">
        <div class="system-form-grid system-form-single">
            <div class="system-field" :class="{'has-danger': errors.token_public_culqui}">
                <label for="culqi-public">Token público</label>
                <el-input id="culqi-public" class="system-mono-input" v-model="form.token_public_culqui" placeholder="pk_live_…"></el-input>
                <small class="form-control-feedback" v-if="errors.token_public_culqui" v-text="errors.token_public_culqui[0]"></small>
            </div>
            <div class="system-field" :class="{'has-danger': errors.token_private_culqui}">
                <label for="culqi-private">Token privado</label>
                <el-input id="culqi-private" class="system-mono-input" :type="reveal ? 'text' : 'password'" v-model="form.token_private_culqui" autocomplete="new-password" :placeholder="has_private ? 'Guardado. Escribe para reemplazar' : 'sk_live_…'">
                    <button slot="suffix" type="button" class="system-input-toggle" aria-label="Mostrar u ocultar token" :aria-pressed="String(reveal)" @click="reveal = !reveal">
                        <svg class="sys-icon" aria-hidden="true"><use :href="$icon((reveal ? 'eye-off' : 'eye'))" /></svg>
                    </button>
                </el-input>
                <small class="form-control-feedback" v-if="errors.token_private_culqui" v-text="errors.token_private_culqui[0]"></small>
                <small class="system-field-hint">Obtén tus credenciales en <a href="https://www.culqi.com" target="_blank" rel="noopener noreferrer">culqi.com</a>.</small>
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
      has_private: false,
      // headers: headers_token,
      resource: "configurations",
      errors: {},
      form: {},
      soap_sends: [],
      soap_types: []
    };
  },
  async created() {
    await this.initForm();

    await this.$http.get(`/${this.resource}/record`).then(response => {
        this.form.token_public_culqui = response.data.token_public_culqui;
        this.has_private = !!response.data.has_token_private_culqui;
        this.emitStatus();
    });
  },
  methods: {
    emitStatus() {
      this.$emit("status", {
        culqi_public: !!this.form.token_public_culqui,
        culqi_private: this.has_private
      });
    },
    initForm() {
      this.errors = {};
      this.form = {
        token_public_culqui: null,
        token_private_culqui: null
      };
    },
    submit() {
      this.loading_submit = true;
      this.$http
        .post(`/${this.resource}`, this.form)
        .then(response => {
          if (response.data.success) {
            if (this.form.token_private_culqui) {
              this.has_private = true;
              this.form.token_private_culqui = null;
            }
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

