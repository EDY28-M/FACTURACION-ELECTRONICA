<template>
  <div>
    <div class="system-switch-row">
      <div>
        <strong id="login-global-label">Usar configuración global</strong>
        <small>Aplica esta apariencia al login de todos los clientes.</small>
      </div>
      <el-switch v-model="form.use_login_global" aria-labelledby="login-global-label"></el-switch>
    </div>
    <div v-if="form.use_login_global" class="system-form-grid">
      <div>
        <ImageBgUpload type="bg" :config="configuration.login"></ImageBgUpload>
        <ImageBgUpload type="logo" :config="configuration.login"></ImageBgUpload>
      </div>
      <div>
        <div class="system-field">
          <label>Posición del formulario</label>
          <el-select v-model="form.position_form" aria-label="Posición del formulario">
            <el-option key="left" value="left" label="Izquierda"></el-option>
            <el-option key="right" value="right" label="Derecha"></el-option>
          </el-select>
          <small class="form-control-feedback" v-if="errors.position_form" v-text="errors.position_form[0]"></small>
        </div>
        <div class="system-switch-row">
          <strong id="login-logo-form">Mostrar logo en el formulario</strong>
          <el-switch v-model="form.show_logo_in_form" aria-labelledby="login-logo-form"></el-switch>
        </div>
        <div class="system-field">
          <label>Posición del logo de la empresa</label>
          <el-select v-model="form.position_logo" aria-label="Posición del logo">
            <el-option key="top-left" value="top-left" label="Superior izquierda"></el-option>
            <el-option key="bottom-left" value="bottom-left" label="Inferior izquierda"></el-option>
            <el-option key="top-right" value="top-right" label="Superior derecha"></el-option>
            <el-option key="bottom-right" value="bottom-right" label="Inferior derecha"></el-option>
            <el-option key="none" value="none" label="Ocultar"></el-option>
          </el-select>
          <small class="form-control-feedback" v-if="errors.position_logo" v-text="errors.position_logo[0]"></small>
        </div>
        <div class="system-switch-row">
          <strong id="login-socials">Mostrar botones de redes sociales</strong>
          <el-switch v-model="form.show_socials" aria-labelledby="login-socials"></el-switch>
        </div>
        <div class="system-field"><label for="login-facebook">Facebook</label><el-input id="login-facebook" v-model="form.facebook"></el-input></div>
        <div class="system-field"><label for="login-twitter">Twitter</label><el-input id="login-twitter" v-model="form.twitter"></el-input></div>
        <div class="system-field"><label for="login-instagram">Instagram</label><el-input id="login-instagram" v-model="form.instagram"></el-input></div>
        <div class="system-field"><label for="login-linkedin">LinkedIn</label><el-input id="login-linkedin" v-model="form.linkedin"></el-input></div>
      </div>
    </div>
    <div class="system-form-actions">
      <button type="button" class="system-button system-button-primary" :disabled="loading" @click="onSubmit">{{ loading ? 'Guardando…' : 'Guardar' }}</button>
    </div>
  </div>
</template>
<script>
import ImageBgUpload from "./UploadBgImage";

export default {
  components: {
    ImageBgUpload,
  },
  props: ["configuration"],
  data() {
    return {
      loading: false,
      form: {
        position_form: "",
        show_logo_in_form: "",
        position_logo: "",
        show_socials: "",
        use_login_global: false,
        facebook: '',
        twitter: '',
        instagram: '',
        linkedin: '',
      },
      errors: {},
    };
  },
  mounted() {
    this.form.position_form = this.configuration.login.position_form;
    this.form.show_logo_in_form = this.configuration.login.show_logo_in_form;
    this.form.position_logo = this.configuration.login.position_logo;
    this.form.show_socials = this.configuration.login.show_socials;
    this.form.use_login_global = this.configuration.use_login_global;
    this.form.facebook = this.configuration.login.facebook;
    this.form.twitter = this.configuration.login.twitter;
    this.form.instagram = this.configuration.login.instagram;
    this.form.linkedin = this.configuration.login.linkedin;
  },
  methods: {
    initForm() {
      this.errors = {};
      this.form = {};
    },
    onSubmit() {
      delete this.form.type;
      delete this.form.image;
      this.loading = true;
      this.$http
        .post("configurations/login", this.form)
        .then((response) => {
          this.$message({
            message: response.data.message,
            type: "success",
          });
          this.$emit("status", { login: !!this.form.use_login_global });
        })
        .catch((error) => this.axiosError(error))
        .finally(() => (this.loading = false));
    },
  },
};
</script>

