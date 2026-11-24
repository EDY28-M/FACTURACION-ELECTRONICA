<template>
    <div class="card mb-0 pt-2 pt-md-0">
        <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="my-0">Tesorería — Feriados</h3>
            <el-button size="small" :loading="loading" @click="loadRecords">
                <i class="fa fa-refresh"></i> Actualizar
            </el-button>
        </div>

        <div class="card-body">
            <p class="text-muted small mb-3">
                Los feriados nacionales se usan para calcular el quinto día hábil del depósito de detracciones. Mientras un año no tenga
                feriados cargados, las fechas de vencimiento de ese año se muestran como estimadas. Los días no laborables del sector público
                son hábiles para fines tributarios y se registran solo como referencia.
            </p>

            <div v-if="errorMessage" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                <span>{{ errorMessage }}</span>
                <el-button size="mini" type="danger" plain @click="loadRecords">Reintentar</el-button>
            </div>

            <form v-if="isAdmin" class="row mb-3" @submit.prevent="submit">
                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="hol-date" class="control-label">Fecha</label>
                    <el-date-picker
                        id="hol-date"
                        v-model="form.date"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="AAAA-MM-DD"
                        class="w-100"
                    />
                    <small v-if="errors.date" class="text-danger">{{ errors.date[0] }}</small>
                </div>
                <div class="col-md-3 col-sm-6 mb-2">
                    <label for="hol-name" class="control-label">Nombre</label>
                    <el-input id="hol-name" v-model="form.name" maxlength="120" placeholder="Ej. Feriado nacional" />
                    <small v-if="errors.name" class="text-danger">{{ errors.name[0] }}</small>
                </div>
                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="hol-ref" class="control-label">Norma legal</label>
                    <el-input id="hol-ref" v-model="form.legal_reference" maxlength="160" placeholder="Ej. Ley 32000" />
                    <small v-if="errors.legal_reference" class="text-danger">{{ errors.legal_reference[0] }}</small>
                </div>
                <div class="col-md-3 col-sm-6 mb-2">
                    <label for="hol-url" class="control-label">Enlace de la fuente</label>
                    <el-input id="hol-url" v-model="form.source_url" maxlength="255" placeholder="https://" />
                    <small v-if="errors.source_url" class="text-danger">{{ errors.source_url[0] }}</small>
                </div>
                <div class="col-md-2 col-sm-12 mb-2 d-flex align-items-end">
                    <el-button type="primary" native-type="submit" :loading="saving" class="w-100">
                        <i class="fa fa-plus"></i> Agregar
                    </el-button>
                </div>
            </form>
            <div v-else class="alert alert-secondary small" role="note">
                Solo un administrador puede agregar o eliminar feriados.
            </div>

            <div class="mb-2">
                <label for="hol-year" class="control-label mr-2">Año</label>
                <el-select id="hol-year" v-model="year" clearable placeholder="Todos" size="small" @change="loadRecords">
                    <el-option v-for="y in years" :key="y" :label="String(y)" :value="y" />
                </el-select>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th class="text-nowrap">Fecha</th>
                            <th>Nombre</th>
                            <th>Norma legal</th>
                            <th>Tipo</th>
                            <th class="text-center">Fuente</th>
                            <th v-if="isAdmin" class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!loading && rows.length === 0">
                            <td :colspan="isAdmin ? 6 : 5" class="text-center text-muted py-3">No hay feriados registrados.</td>
                        </tr>
                        <tr v-for="row in rows" :key="row.id">
                            <td class="text-nowrap">{{ row.date }}</td>
                            <td>{{ row.name }}</td>
                            <td>{{ row.legal_reference || '—' }}</td>
                            <td>
                                <span v-if="row.is_business_day_override" class="badge badge-secondary">Día no laborable (hábil)</span>
                                <span v-else class="badge badge-info">Feriado</span>
                            </td>
                            <td class="text-center">
                                <a v-if="row.source_url" :href="row.source_url" target="_blank" rel="noopener noreferrer">Ver</a>
                                <span v-else>—</span>
                            </td>
                            <td v-if="isAdmin" class="text-center">
                                <el-button size="mini" type="danger" plain @click="remove(row)">Eliminar</el-button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script>
import { parseError } from '../reconciliation/helpers'

export default {
    name: 'TreasuryHolidaysIndex',
    data() {
        return {
            loading: false,
            saving: false,
            errorMessage: null,
            errors: {},
            rows: [],
            years: [],
            year: null,
            isAdmin: false,
            form: this.emptyForm(),
        }
    },
    created() {
        this.loadRecords()
    },
    methods: {
        emptyForm() {
            return { date: null, name: '', legal_reference: '', source_url: '', is_business_day_override: false }
        },
        async loadRecords() {
            this.loading = true
            this.errorMessage = null
            try {
                const params = {}
                if (this.year) {
                    params.year = this.year
                }
                const { data } = await this.$http.get('/treasury/holidays/records', { params })
                this.rows = data.data
                this.years = data.years
                this.isAdmin = Boolean(data.is_admin)
            } catch (error) {
                this.errorMessage = parseError(error).general
            } finally {
                this.loading = false
            }
        },
        async submit() {
            this.saving = true
            this.errors = {}
            try {
                await this.$http.post('/treasury/holidays', this.form)
                this.$message.success('Feriado registrado.')
                this.form = this.emptyForm()
                await this.loadRecords()
            } catch (error) {
                const parsed = parseError(error)
                this.errors = parsed.fields
                if (!Object.keys(parsed.fields).length) {
                    this.$message.error(parsed.general)
                }
            } finally {
                this.saving = false
            }
        },
        remove(row) {
            this.$confirm(`¿Eliminar el feriado del ${row.date}?`, 'Eliminar feriado', {
                confirmButtonText: 'Eliminar',
                cancelButtonText: 'Cancelar',
                type: 'warning',
            }).then(async () => {
                try {
                    await this.$http.delete(`/treasury/holidays/${row.id}`)
                    this.$message.success('Feriado eliminado.')
                    await this.loadRecords()
                } catch (error) {
                    this.$message.error(parseError(error).general)
                }
            }).catch(() => {})
        },
    },
}
</script>
