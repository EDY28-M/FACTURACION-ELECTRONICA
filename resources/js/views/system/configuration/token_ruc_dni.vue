<template>
    <form autocomplete="off" @submit.prevent="submit">
        <div class="system-form-grid system-form-single">
            <div class="system-field" :class="{'has-danger': errors.token_false}">
                <el-checkbox v-model="form.token_false">Usar consulta interna</el-checkbox>
                <small class="form-control-feedback" v-if="errors.token_false" v-text="errors.token_false[0]"></small>
            </div>
            <template v-if="form.token_false == false">
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
            </template>
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

        if (this.form.token_apiruc == 'false') {
          this.form.token_false = true;
        }
        this.emitStatus();
    });
  },
  methods: {
    emitStatus() {
      const internal = this.form.token_false === true || this.form.token_apiruc === "false";
      this.$emit("status", {
        ruc: internal || !!(this.form.url_apiruc && this.form.token_apiruc),
        ruc_partial: internal || !!(this.form.url_apiruc || this.form.token_apiruc),
        ruc_internal: internal
      });
    },
    initForm() {
      this.errors = {};
      this.form = {
        url_apiruc: null,
        token_apiruc: null,
        token_false: false
      };
    },
    submit() {
      this.loading_submit = true;
      if (this.form.token_false == true) {
        this.form.token_apiruc = 'false';
      }
      this.$http
        .post(`/${this.resource}`, this.form)
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

