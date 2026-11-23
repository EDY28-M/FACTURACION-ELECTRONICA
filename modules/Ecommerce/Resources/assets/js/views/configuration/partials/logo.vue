<template>
    <section aria-labelledby="sc-logo-title">
        <h4 id="sc-logo-title" class="sc-section-title">Logo de la tienda</h4>
        <p class="co-help sc-section-help">Aparece en la cabecera de la tienda. Si no sube uno, se usa el logo de la empresa o el nombre de la tienda.</p>

        <div class="co-image sc-logo">
            <div class="co-image-preview">
                <img v-if="previewUrl" :src="previewUrl" alt="Vista previa del logo de la tienda" @error="broken = true">
                <span v-else>Sin logo</span>
            </div>
            <div class="co-image-body">
                <span class="co-image-title">{{ hasOwnLogo ? 'Logo actual de la tienda' : (previewUrl ? 'Usando el logo de la empresa' : 'Sin logo') }}</span>
                <small class="co-help">JPG, PNG o GIF de hasta 2 MB. Tamaño recomendado 700 x 300 px; en la cabecera se muestra con un alto máximo de 180 px. El archivo se guarda al subirlo.</small>
                <div class="sc-logo-actions">
                    <el-upload
                        :headers="headers"
                        :data="{ type: 'logo_store' }"
                        action="/ecommerce/uploads"
                        name="file"
                        accept="image/jpeg,image/png,image/gif"
                        :show-file-list="false"
                        :before-upload="beforeUpload"
                        :on-success="onSuccess"
                        :on-error="onError"
                    >
                        <el-button size="small" icon="el-icon-upload" :loading="uploading">{{ hasOwnLogo ? 'Reemplazar logo' : 'Subir logo' }}</el-button>
                    </el-upload>
                    <el-button v-if="hasOwnLogo" size="small" type="text" class="sc-danger" :loading="removing" @click="remove">Quitar logo</el-button>
                </div>
            </div>
        </div>
    </section>
</template>

<script>
import { requestErrorMessage, normalizeErrors } from '../section'

const MAX_BYTES = 2 * 1024 * 1024
const TYPES = ['image/jpeg', 'image/png', 'image/gif']

export default {
    props: {
        record: { type: Object, default: null }
    },
    data() {
        return {
            headers: typeof headers_token !== 'undefined' ? headers_token : {},
            uploading: false,
            removing: false,
            broken: false
        }
    },
    computed: {
        hasOwnLogo() {
            return !!(this.record && this.record.logo_url)
        },
        previewUrl() {
            if (this.broken || !this.record) return null
            return this.record.logo_url || this.record.company_logo_url || null
        }
    },
    watch: {
        'record.logo_url'() {
            this.broken = false
        }
    },
    methods: {
        beforeUpload(file) {
            if (TYPES.indexOf(file.type) === -1) {
                this.$message.error('El logo debe ser JPG, PNG o GIF.')
                return false
            }
            if (file.size > MAX_BYTES) {
                this.$message.error('El logo no debe superar 2 MB.')
                return false
            }
            this.uploading = true
            return true
        },
        onSuccess(response) {
            this.uploading = false
            if (response && response.success) {
                this.$message.success(response.message)
                this.$emit('saved', Object.assign({}, this.record, { logo: response.name, logo_url: response.logo_url }))
            } else {
                this.$message.error((response && response.message) || 'No se pudo subir el logo.')
            }
        },
        onError(error) {
            this.uploading = false
            let message = 'No se pudo subir el logo. Intente nuevamente.'
            try {
                const data = JSON.parse(error.message)
                const errors = normalizeErrors(data)
                message = (errors.file && errors.file[0]) || data.message || message
            } catch (e) {}
            this.$message.error(message)
        },
        async remove() {
            try {
                await this.$confirm('El logo se eliminará de la tienda. ¿Continuar?', 'Quitar logo', {
                    confirmButtonText: 'Quitar',
                    cancelButtonText: 'Cancelar',
                    type: 'warning'
                })
            } catch (cancelled) {
                return
            }
            this.removing = true
            try {
                const { data } = await this.$http.post('/ecommerce/configuration_logo/remove')
                this.$message.success(data.message)
                this.$emit('saved', Object.assign({}, this.record, { logo: null, logo_url: null }))
            } catch (error) {
                this.$message.error(requestErrorMessage(error, 'No se pudo quitar el logo.'))
            } finally {
                this.removing = false
            }
        }
    }
}
</script>
