<template>
    <div>
        <div class="page-header pr-0">
            <h2><a href="#"><i class="fas fa-cogs"></i></a></h2>
            <ol class="breadcrumbs">
                <li class="active"><span>Configuración</span> </li>
                <li><span class="text-muted">Plantilla PDF</span></li>
            </ol>
            <div class="right-wrapper pull-right">
                <button type="button" @click="addSeeder" class="btn btn-custom btn-sm  mt-2 mr-2"><i class="el-icon-refresh"></i> Actualizar listado</button>
            </div>
        </div>
        <div class="card">
            <div class="card-header bg-info">
                <h3 class="my-0">Selección de plantilla de impresión para comprobantes</h3>
            </div>
            <div class="card-body pt-0 pb-5" v-loading="loading">
                <h3>Plantilla actual: <a :href="'#'+form.formats" class="text-secondary">{{form.formats}}</a></h3>
                <p class="text-muted mb-3">Las vistas previas usan un logo de ejemplo. El logo de tus comprobantes se sube en <a href="/companies/create">Configuración → Empresa</a>.</p>
                <div class="row">
                  <div v-for="o in visibleFormatos" :key="o.formats" class="col-md-3 my-2">
                    <el-card :body-style="{ padding: '0px' }" :id="o.formats">
                      <a @click="viewImage(o.formats)">
                      <img v-if="o.preview && !failed[o.formats]" :src="path.origin + o.preview" class="image" style="width: 100%" @error="onImageError(o.formats)">
                      <div v-else class="tpl-placeholder">{{ o.formats }}</div>
                      </a>
                      <div style="padding: 14px;">
                        <span class="text-center">{{o.formats}}</span>
                        <div class="bottom clearfix text-right">
                            <!-- <el-button type="submit" class="button" @change="changeFormat(o.formats)">Activo</el-button> -->
                            <el-radio v-model="form.formats" :label="o.formats" @change="changeFormat(o.formats)">
                                <span v-if="form.formats == o.formats">Activo</span>
                                <span v-else>Activar</span>
                            </el-radio>
                        </div>
                      </div>
                    </el-card>
                  </div>
                </div>
                <div v-if="!loading && !visibleFormatos.length" class="tpl-empty">
                    <p class="mb-1">No hay plantillas disponibles.</p>
                    <small>Presione "Actualizar listado" para volver a cargar las plantillas.</small>
                </div>
            </div>
        </div>
        <el-dialog
           :visible.sync="modalImage"
           width="60">
            <span>
                <img v-if="templatePreview" :src="path.origin + templatePreview" class="image" style="width: 100%" @error="onImageError(template)">
                <div v-else class="tpl-placeholder">{{ template }}</div>
            </span>
            <span slot="footer" class="dialog-footer">
                <el-button @click="modalImage = false">Cerrar</el-button>
                <el-button @click="changeFormat(template)" type="primary">Activar</el-button>
            </span>
        </el-dialog>
    </div>
</template>

<script>

    export default {
        props:['path_image'],

        data() {
            return {
                loading_submit: false,
                resource: 'configurations',
                errors: {},
                form: {},
                formatos: [],
                loading: false,
                failed: {},
                path: location,
                modalImage: false,
                template: '',
            }
        },
        computed: {
            visibleFormatos() {
                return this.formatos.filter(f => f.supports_invoice || f.missing || f.formats === this.form.formats)
            },
            templatePreview() {
                const f = this.formatos.find(r => r.formats === this.template)
                return f && !this.failed[this.template] ? f.preview : null
            },
        },
        async created() {

            await this.$http.get(`/${this.resource}/record`) .then(response => {
                if (response.data !== ''){
                this.form = response.data.data;
                }
                // console.log(this.placeholder)
            });

            this.loading = true
            try {
                const response = await this.$http.get(`/${this.resource}/getFormats`)
                this.formatos = Array.isArray(response.data) ? response.data : []
            } catch (e) {
                this.formatos = []
            } finally {
                this.loading = false
            }

        },
        methods: {
            changeFormat(value){
                this.modalImage = false
                this.$http.post(`/${this.resource}/changeFormat`, { formats: value }).then(response =>{
                    this.$message.success(response.data.message);
                    location.reload()
                })

            },
            addSeeder(){
                var ruta = location.host
                this.$http.get(`/${this.resource}/addSeeder`).then(response =>{
                    this.$message.success(response.data.message);
                    location.reload()
                })
            },
            viewImage($value){
                this.template = $value

                this.modalImage = true
            },
            onImageError(name) {
                this.$set(this.failed, name, true)
            },
        }
    }
</script>

<style scoped>
.tpl-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 180px;
    padding: 12px;
    background: #f5f7fa;
    color: #909399;
    text-align: center;
    word-break: break-word;
    cursor: pointer;
}
.tpl-empty {
    padding: 40px 0;
    text-align: center;
    color: #909399;
}
</style>
