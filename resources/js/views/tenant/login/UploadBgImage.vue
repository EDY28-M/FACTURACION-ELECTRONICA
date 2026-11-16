<template>
  <div>
    <img
      v-if="hasCustomImage"
      :src="imageUrl"
      alt="Vista previa"
      class="img-fluid img-thumbnail w-100 mb-3"
    />
    <div v-else class="login-bg-default mb-3">
      <strong>Animación predeterminada</strong>
      <span>El inicio de sesión muestra la ilustración animada. Sube una imagen para reemplazarla.</span>
    </div>
    <input
      type="file"
      accept=".png,.jpg,.jpeg,.webp,.gif,.svg"
      @change="onGeneratePreview"
      ref="inputFile"
      class="hidden"
    />
    <small class="form-control-feedback">Se recomienda una imagen de 747 x 547px con fondo transparente en formato PNG o SVG</small>
    <el-button type="primary" class="btn-block" @click="onShowFilePicker" :loading="loading" :disabled="loading"
      >Cambiar imagen de fondo</el-button
    >
    <el-button v-if="hasCustomImage" class="btn-block ml-0 mt-2" @click="onReset" :disabled="loading"
      >Usar animación predeterminada</el-button
    >
  </div>
</template>

<script>
// The seeded default background; the sign-in page treats it as "no custom image" and shows the animation.
const DEFAULT_BG = "/images/login-v2.svg";

export default {
  props: {
    config: {
      type: Object,
      required: true,
    },
  },
  data() {
    return {
      imageUrl: "",
      loading: false,
    };
  },
  computed: {
    hasCustomImage() {
      return !!this.imageUrl && !this.imageUrl.split("?")[0].endsWith(DEFAULT_BG);
    },
  },
  mounted() {
    this.imageUrl = this.config.type === "image" ? this.config.image || "" : "";
  },
  methods: {
    onShowFilePicker() {
      this.$refs.inputFile.click();
    },
    onGeneratePreview(event) {
      const files = event.target.files;
      if (files.length > 0) {
        const previous = this.imageUrl;
        const fileReader = new FileReader();
        fileReader.addEventListener("load", () => {
          this.imageUrl = fileReader.result;
        });
        fileReader.readAsDataURL(files[0]);
        const image = files[0];
        const payload = new FormData();
        payload.append("image", image);
        this.loading = true;
        this.$http
          .post("/login-page/upload-bg-image", payload)
          .then((response) => {
            this.$message({
              message: response.data.message,
              type: "success",
            });
          })
          .catch((error) => {
            this.imageUrl = previous;
            const errors = error.response && error.response.data && error.response.data.errors;
            this.$message.error(errors && errors.image ? errors.image[0] : "No se pudo subir la imagen.");
          })
          .finally(() => {
            this.loading = false;
            this.$refs.inputFile.value = "";
          });
      }
    },
    onReset() {
      this.loading = true;
      this.$http
        .post("/login-page/reset-bg-image")
        .then((response) => {
          this.imageUrl = "";
          this.$message({
            message: response.data.message,
            type: "success",
          });
        })
        .finally(() => (this.loading = false));
    },
  },
};
</script>

<style scoped>
.login-bg-default {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 6px;
  min-height: 220px;
  padding: 24px;
  border-radius: 16px;
  background: var(--tenant-surface-subtle, #f3f7fe);
  color: var(--tenant-muted, #5f6e83);
  font-size: 13px;
  text-align: center;
}

.login-bg-default strong {
  color: var(--tenant-ink, #0e1b2e);
  font-size: 14px;
}
</style>
