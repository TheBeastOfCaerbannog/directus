import { FieldFilterOperator, Type } from '@directus/shared/types';

// Since every field can be null/not null regardless of type, using _nnull as fallback should be the safest option
export const FALLBACK_FILTER_OPERATOR = { _nnull: true };

export function getDefaultFieldFilterByType(type: Type): FieldFilterOperator {
	switch (type) {
		case 'binary':
		case 'hash':
		case 'string':
		case 'csv':
		case 'uuid':
		case 'boolean':
		case 'bigInteger':
		case 'integer':
		case 'decimal':
		case 'float':
		case 'dateTime':
		case 'date':
		case 'time':
			return { _eq: undefined };
		case 'geometry':
		case 'json':
		default:
			return FALLBACK_FILTER_OPERATOR;
	}
}
