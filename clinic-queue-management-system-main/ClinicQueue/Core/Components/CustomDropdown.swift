import SwiftUI

struct CustomDropdown: View {
    var placeholder: String
    var options: [DropdownOption]

    @Binding var selectedKey: String?
    @FocusState var focusedField: FormField?

    var borderRadius: CGFloat = 100
    var borderWidth: CGFloat = 1
    var borderColor: Color = AppColors.border
    var padding: CGFloat = 12

    @State private var isOpen = false

    var body: some View {
        VStack(spacing: 0) {
            Button {
                withAnimation {
                    isOpen.toggle()
                    focusedField = isOpen ? .gender : nil
                }
            } label: {
                HStack {
                    Text(selectedLabel ?? placeholder)
                        .foregroundColor(selectedKey == nil ? AppColors.placeholder : AppColors.text)
                        .allowsHitTesting(false) 

                    Spacer()

                    Image(systemName: "chevron.down")
                        .font(.system(size: 14))
                        .rotationEffect(.degrees(isOpen ? 180 : 0))
                }
                .padding(padding)
                .frame(maxWidth: .infinity)
                .background(Color.white)
                .overlay(
                    RoundedRectangle(cornerRadius: borderRadius)
                        .stroke(focusedField == .gender ? AppColors.primary : borderColor, lineWidth: borderWidth)
                        .animation(.easeInOut(duration: 0.2), value: focusedField)
                )
            }

            if isOpen {
                VStack(spacing: 0) {
                    ForEach(options) { option in
                        Button {
                            selectedKey = option.key
                            withAnimation {
                                isOpen = false
                                focusedField = nil
                            }
                        } label: {
                            HStack {
                                Text(option.label)
                                    .foregroundColor(AppColors.text)
                                Spacer()
                            }
                            .padding(padding)
                        }

                        if option.id != options.last?.id {
                            Divider()
                        }
                    }
                }
                .background(Color.white)
                .overlay(
                    RoundedRectangle(cornerRadius: 12)
                        .stroke(borderColor, lineWidth: 1)
                )
                .frame(maxWidth: .infinity)
            }
        }
        .frame(maxWidth: .infinity)
    }

    private var selectedLabel: String? {
        options.first { $0.key == selectedKey }?.label
    }
}
