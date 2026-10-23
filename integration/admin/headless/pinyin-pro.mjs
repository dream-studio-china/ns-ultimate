// Pinyin conversion is only called inside component setup/methods.
export const pinyin = value => String(value ?? '')

export default { pinyin }
