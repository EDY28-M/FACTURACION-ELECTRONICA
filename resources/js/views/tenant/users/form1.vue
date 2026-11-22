<template>
  <el-dialog
    :title="titleDialog"
    :visible="showDialog"
    @close="close"
    @open="create"
  >
    <form autocomplete="off" @submit.prevent="submit">
      <div class="form-body">
        <div class="row">
          <div class="col-md-4">
            <div class="form-group" :class="{ 'has-danger': errors.name }">
              <label class="control-label">Nombre</label>
              <el-input v-model="form.name"></el-input>
              <small
                class="form-control-feedback"
                v-if="errors.name"
                v-text="errors.name[0]"
              ></small>
            </div>
          </div>
          <div class="col-md-4">
            <div class="form-group" :class="{ 'has-danger': errors.email }">
              <label class="control-label">Correo Electrónico</label>
              <el-input
                v-model="form.email"
                :disabled="form.id != null"
              ></el-input>
              <small
                class="form-control-feedback"
                v-if="errors.email"
                v-text="errors.email[0]"
              ></small>
            </div>
          </div>
          <div class="col-md-4">
            <div
              class="form-group"
              :class="{ 'has-danger': errors.establishment_id }"
            >
              <label class="control-label">Establecimiento</label>
              <el-select v-model="form.establishment_id" filterable>
                <el-option
                  v-for="option in establishments"
                  :key="option.id"
                  :value="option.id"
                  :label="option.description"
                ></el-option>
              </el-select>
              <small
                class="form-control-feedback"
                v-if="errors.establishment_id"
                v-text="errors.establishment_id[0]"
              ></small>
            </div>
          </div>
          <div class="col-md-12" v-show="form.id">

            <div class="form-group" :class="{ 'has-danger': errors.api_token }">
              <label class="control-label">Api Token</label>
              <el-input
                v-if="form.api_token"
                class="user-token-input"
                :value="showToken ? form.api_token : form.api_token_hint"
                readonly
              >
                <template slot="append">
                  <el-button @click="showToken = !showToken">{{ showToken ? 'Ocultar' : 'Mostrar' }}</el-button>
                  <el-button @click="copyToken">Copiar</el-button>
                </template>
              </el-input>
              <template v-else>
                <el-input class="user-token-input" :value="form.api_token_hint || ''" readonly></el-input>
                <small class="user-token-help">Solo el propio usuario o un administrador puede ver el token completo.</small>
              </template>
              <small
                class="form-control-feedback"
                v-if="errors.api_token"
                v-text="errors.api_token[0]"
              ></small>
            </div>
          </div>
          <div class="col-md-4">
            <div class="form-group" :class="{ 'has-danger': errors.password }">
              <label class="control-label">Contraseña</label>
              <el-input v-model="form.password"></el-input>
              <small
                class="form-control-feedback"
                v-if="errors.password"
                v-text="errors.password[0]"
              ></small>
            </div>
          </div>
          <div class="col-md-4">
            <div
              class="form-group"
              :class="{ 'has-danger': errors.password_confirmation }"
            >
              <label class="control-label">Confirmar Contraseña</label>
              <el-input v-model="form.password_confirmation"></el-input>
              <small
                class="form-control-feedback"
                v-if="errors.password_confirmation"
                v-text="errors.password_confirmation[0]"
              ></small>
            </div>
          </div>
          <div class="col-md-4" v-if="typeUser != 'integrator'">
            <div class="form-group" :class="{ 'has-danger': errors.type }">
              <label class="control-label">Perfil</label>
              <el-select v-model="form.type" :disabled="form.is_owner">
                <el-option
                  v-for="option in types"
                  :key="option.type"
                  :value="option.type"
                  :label="option.description"
                ></el-option>
              </el-select>
              <small
                class="form-control-feedback"
                v-if="errors.type"
                v-text="errors.type[0]"
              ></small>
            </div>
          </div>
          <div class="col-md-8" v-if="typeUser != 'integrator'">
            <div class="form-comtrol">
              <label class="control-label">Permisos Módulos</label>

              <div v-if="form.is_owner" class="alert alert-info mb-0">
                El usuario principal tiene acceso total.
              </div>
              <div v-else id="app">
                <small
                  class="form-control-feedback text-danger d-block"
                  v-if="errors.modules"
                  v-text="errors.modules[0]"
                ></small>
                <div class="control_wrapper">
                  <ejs-treeview
                    id="treeview"
                    :fields="fields"
                    :showCheckBox="true"
                    :loadOnDemand="false"
                    :nodeChecked="nodeChecked"
                  ></ejs-treeview>
                </div>
              </div>
            </div>
          </div>
          <!--
          <div class="col-md-12" v-if="typeUser != 'integrator'">
            <div class="form-group">
              <label class="control-label">Módulos</label>
              <div class="row">
                <div class="col-4" v-for="module in form.modules">
                  <el-checkbox
                    v-model="module.checked"
                    :disabled="form.locked"
                    @change="changeModule(module.id, module.checked)"
                    >{{ module.description }}</el-checkbox
                  >
                </div>
              </div>
            </div>
          </div>
          <div
            class="col-md-12 mt-3"
            v-if="typeUser != 'integrator' && show_levels"
          >
            <div class="form-group">
              <label class="control-label"
                >Nivel de acceso del módulo ventas</label
              >
              <div class="row">
                <div class="col-4" v-for="level in form.levels">
                  <el-checkbox
                    v-model="level.checked"
                    :disabled="form.locked"
                    >{{ level.description }}</el-checkbox
                  >
                </div>
              </div>
            </div>
          </div>-->
        </div>
      </div>
      <div class="form-actions text-right mt-4">
        <el-button @click.prevent="close()">Cancelar</el-button>
        <el-button type="primary" native-type="submit" :loading="loading_submit" :disabled="loading_record"
          >Guardar</el-button
        >
      </div>
    </form>
  </el-dialog>
</template>

<script>
import { EventBus } from "../../../helpers/bus";
// import the component
import Treeselect from "@riophae/vue-treeselect";
// import the styles
import "@riophae/vue-treeselect/dist/vue-treeselect.css";

import Vue from "vue";
import { TreeViewPlugin } from "@syncfusion/ej2-vue-navigations";

Vue.use(TreeViewPlugin);

export default {
  props: ["showDialog", "recordId", "typeUser"],
  components: { Treeselect },
  data() {
    return {
      loading_submit: false,
      loading_record: false,
      titleDialog: null,
      resource: "users",
      errors: {},
      form: {},
      modules: [],
      datai: [],
      establishments: [],
      types: [],
      show_levels: false,
      datasource: [],
      fields: {},
      // define the default value
      value: null,
      // define options
      alwaysOpen: true,
      options: [],
      showToken: false,
    };
  },
  async created() {
    await this.$http.get(`/${this.resource}/tables`).then((response) => {
      //this.options = response.data.datasource;
      this.fields = {
        dataSource: response.data.datasource,
        id: "id",
        parentID: "pid",
        text: "name",
        hasChildren: "hasChild",
      };

      this.modules = response.data.modules;
      this.establishments = response.data.establishments;
      this.types = response.data.types;
    });
    await this.initForm();
  },
  methods: {
    copyToken() {
      const text = this.form.api_token || "";
      const done = () => this.$message.success("Token copiado");
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, () => this.$message.warning("No se pudo copiar el token"));
        return;
      }
      const area = document.createElement("textarea");
      area.value = text;
      area.setAttribute("readonly", "");
      area.style.position = "fixed";
      area.style.opacity = "0";
      document.body.appendChild(area);
      area.select();
      const ok = document.execCommand("copy");
      document.body.removeChild(area);
      ok ? done() : this.$message.warning("No se pudo copiar el token");
    },
    initForm() {
      this.showToken = false;
      this.errors = {};
      this.form = {
        id: null,
        name: null,
        email: null,
        api_token: null,
        establishment_id: null,
        password: null,
        password_confirmation: null,
        locked: false,
        type: null,
        is_owner: false,
        modules: [],
        levels: [],
      };
      /*this.modules.forEach((module) => {
        this.form.modules.push({
          id: module.id,
          description: module.description,
          checked: false,
        });
      });*/

      this.show_levels = false;
    },
    create() {
      this.titleDialog = this.recordId ? "Editar Usuario" : "Nuevo Usuario";
      this.loading_record = true;
      if (this.recordId) {
        this.$http
          .get(`/${this.resource}/record/${this.recordId}`)
          .then((response) => {

            this.fields = {
              dataSource: response.data.data.dataSource,
              id: "id",
              parentID: "pid",
              text: "name",
              hasChildren: "hasChild",
            };
            this.form = response.data.data;
            this.show_levels = this.form.levels.length > 0 ? true : false;
          })
          .catch(() => {})
          .then(() => {
            this.loading_record = false;
          });
      } else {

        this.$http.get(`/${this.resource}/tables`).then((response) => {
          this.fields = {
            dataSource: response.data.datasource,
            id: "id",
            parentID: "pid",
            text: "name",
            hasChildren: "hasChild",
          };

          this.modules = response.data.modules;
          this.establishments = response.data.establishments;
          this.types = response.data.types;
        })
        .catch(() => {})
        .then(() => {
          this.loading_record = false;
        });
      }
    },
    submit() {
      // Rebuild the permission lists from the tree right before saving (the tree is the source of truth).
      if (!this.form.is_owner && this.syncPermissionsFromTree()) {
        if (this.form.modules.length === 0) {
          this.errors = { modules: ["Seleccione al menos un módulo."] };
          this.$message.error("Seleccione al menos un módulo");
          return;
        }
      }
      this.loading_submit = true;
      this.$http
        .post(`/${this.resource}`, this.form)
        .then((response) => {
          if (response.data.success) {
            this.form.password = null;
            this.form.password_confirmation = null;
            this.$message.success(response.data.message);
            this.$eventHub.$emit("reloadData");
            this.close();
          } else {
            this.$message.error(response.data.message);
          }
        })
        .catch((error) => {
          if (error.response.status === 422) {
            const data = error.response.data;
            this.errors = data.errors || data;
            if (this.errors.modules) {
              this.$message.error(this.errors.modules[0]);
            }
          } else {
            this.$message.error(error.response.data.message);
          }
        })
        .then(() => {
          this.loading_submit = false;
        });
    },
    nodeChecked: function () {
      this.syncPermissionsFromTree();
    },
    // Returns false when the tree is not rendered (nothing is recomputed in that case).
    syncPermissionsFromTree() {
      const element = document.getElementById("treeview");
      if (!element || !element.ej2_instances) {
        return false;
      }
      const treeObj = element.ej2_instances[0];
      const modules = [];
      const levels = [];
      // Checked leaves are "moduleId-levelId"; a fully checked parent is its plain id.
      // The parent of any checked child is included even when it shows as indeterminate.
      treeObj.checkedNodes.forEach((node) => {
        const parts = String(node).split("-");
        if (parts.length > 1) {
          levels.push({ id: Number(parts[1]), checked: true });
        }
        const moduleId = Number(parts[0]);
        if (!modules.some((m) => m.id === moduleId)) {
          modules.push({ id: moduleId, checked: true });
        }
      });
      this.form.modules = modules;
      this.form.levels = levels;
      return true;
    },
    close() {

      this.$emit("update:showDialog", false);
      this.initForm();
    },
  },
};
</script>
<style>
@import "../../../../../node_modules/@syncfusion/ej2-base/styles/material.css";
@import "../../../../../node_modules/@syncfusion/ej2-vue-navigations/styles/material.css";
@import "../../../../../node_modules/@syncfusion/ej2-buttons/styles/material.css";
.control_wrapper {
  display: block;
  max-height: 350px;
  overflow: auto;
  border: 1px solid #dddddd;
  border-radius: 3px;
}
.user-token-input .el-input__inner {
  font-family: var(--tenant-font-mono, ui-monospace, monospace);
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.02em;
}
.user-token-help {
  display: block;
  margin-top: 6px;
  color: var(--tenant-muted, #5f6e83);
}
.e-checkbox-wrapper .e-frame.e-check, .e-css.e-checkbox-wrapper .e-frame.e-check {
    background-color: #0088CC;
    border-color: transparent;
    color: #fff;
}
.e-checkbox-wrapper:hover .e-frame.e-check, .e-css.e-checkbox-wrapper:hover .e-frame.e-check {
    background-color: #0088CC;
    border-color: transparent;
    color: #fff;
}
</style>
