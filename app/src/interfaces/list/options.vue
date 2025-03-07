<template>
	<div class="grid">
		<div class="grid-element half">
			<p class="type-label">{{ t('template') }}</p>
			<v-input v-model="template" class="input" :placeholder="`{{ field }}`" />
		</div>

		<div class="grid-element half">
			<p class="type-label">{{ t('interfaces.list.add_label') }}</p>
			<v-input v-model="addLabel" class="input" :placeholder="t('create_new')" />
		</div>

		<div class="grid-element full">
			<p class="type-label">{{ t('interfaces.list.edit_fields') }}</p>
			<repeater
				:value="repeaterValue"
				:template="`{{ field }} — {{ interface }}`"
				:fields="repeaterFields"
				@input="repeaterValue = $event"
			/>
		</div>
	</div>
</template>

<script lang="ts">
import { useI18n } from 'vue-i18n';
import { defineComponent, PropType, computed } from 'vue';
import Repeater from './list.vue';
import { Field, FieldMeta } from '@directus/shared/types';
import { fieldTypes } from '@/modules/settings/routes/data-model/field-detail/components/schema.vue';
import { DeepPartial } from '@directus/shared/types';
import { translate } from '@/utils/translate-object-values';

export default defineComponent({
	components: { Repeater },
	props: {
		value: {
			type: Object as PropType<Record<string, any>>,
			default: null,
		},
	},
	emits: ['input'],
	setup(props, { emit }) {
		const { t } = useI18n();

		const repeaterValue = computed({
			get() {
				return props.value?.fields?.map((field: Field) => field.meta);
			},
			set(newVal: FieldMeta[] | null) {
				const fields = (newVal || []).map((meta: Record<string, any>) => ({
					field: meta.field,
					name: meta.name || meta.field,
					type: meta.type,
					meta,
				}));

				emit('input', {
					...(props.value || {}),
					fields: fields,
				});
			},
		});

		const repeaterFields: DeepPartial<Field>[] = [
			{
				name: t('field', 1),
				field: 'field',
				type: 'string',
				meta: {
					interface: 'input',
					width: 'half',
					sort: 2,
					options: {
						dbSafe: true,
						font: 'monospace',
						placeholder: t('interfaces.list.field_name_placeholder'),
					},
				},
				schema: null,
			},
			{
				name: t('field_width'),
				field: 'width',
				type: 'string',
				meta: {
					interface: 'select-dropdown',
					width: 'half',
					sort: 3,
					options: {
						choices: [
							{
								value: 'half',
								text: t('half_width'),
							},
							{
								value: 'full',
								text: t('full_width'),
							},
						],
					},
				},
				schema: null,
			},
			{
				name: t('type'),
				field: 'type',
				type: 'string',
				meta: {
					interface: 'select-dropdown',
					width: 'half',
					sort: 4,
					options: {
						choices: translate(fieldTypes),
					},
				},
				schema: null,
			},
			{
				name: t('interface_label'),
				field: 'interface',
				type: 'string',
				meta: {
					interface: 'system-interface',
					width: 'half',
					sort: 5,
					options: {
						typeField: 'type',
					},
				},
				schema: null,
			},
			{
				name: t('note'),
				field: 'note',
				type: 'string',
				meta: {
					interface: 'input',
					width: 'full',
					sort: 6,
					options: {
						placeholder: t('interfaces.list.field_note_placeholder'),
					},
				},
				schema: null,
			},
			{
				name: t('options'),
				field: 'options',
				type: 'string',
				meta: {
					interface: 'system-interface-options',
					width: 'full',
					sort: 7,
					options: {
						interfaceField: 'interface',
					},
				},
			},
		];

		const template = computed({
			get() {
				return props.value?.template;
			},
			set(newTemplate: string) {
				emit('input', {
					...(props.value || {}),
					template: newTemplate,
				});
			},
		});

		const addLabel = computed({
			get() {
				return props.value?.addLabel;
			},
			set(newAddLabel: string) {
				emit('input', {
					...(props.value || {}),
					addLabel: newAddLabel,
				});
			},
		});

		return { t, repeaterValue, repeaterFields, template, addLabel };
	},
});
</script>

<style lang="scss" scoped>
@import '@/styles/mixins/form-grid';

.grid {
	@include form-grid;

	&-element {
		&.full {
			grid-column: start/full;
		}
	}
}
</style>
