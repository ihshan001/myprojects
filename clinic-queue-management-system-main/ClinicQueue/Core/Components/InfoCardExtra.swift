import SwiftUI

struct BloodTestCard: View {
    var image: String
    var title: String
    var specialText: String
    var detailLine1: String
    var detailLine2: String

    var showExtraSection: Bool = false
    var bottomTitleLeft: String = ""
    var listItems: [String] = []
    var bottomTitleRight: String = ""
    var bottomSubTextRight: String = ""

    var fee: String
    var onButtonTap: (() -> Void)?

    var isCheckboxSelectable: Bool = false
    var initiallySelected: Bool = false
    @State private var isSelected: Bool = false
    var onSelectionChange: ((Bool) -> Void)?
    var onRescheduleTap: (() -> Void)? = nil

    var isActiveQueue: Bool = false



    init(
        image: String,
        title: String,
        specialText: String,
        detailLine1: String,
        detailLine2: String,
        showExtraSection: Bool = false,
        bottomTitleLeft: String = "",
        listItems: [String] = [],
        bottomTitleRight: String = "",
        bottomSubTextRight: String = "",
        fee: String,
        onButtonTap: (() -> Void)? = nil,
        onRescheduleTap: (() -> Void)? = nil,
        isCheckboxSelectable: Bool = false,
        initiallySelected: Bool = false,
        onSelectionChange: ((Bool) -> Void)? = nil,
        isActiveQueue: Bool = false
    ) {
        self.image = image
        self.title = title
        self.specialText = specialText
        self.detailLine1 = detailLine1
        self.detailLine2 = detailLine2
        self.showExtraSection = showExtraSection
        self.bottomTitleLeft = bottomTitleLeft
        self.listItems = listItems
        self.bottomTitleRight = bottomTitleRight
        self.bottomSubTextRight = bottomSubTextRight
        self.fee = fee
        self.onButtonTap = onButtonTap
        self.onRescheduleTap = onRescheduleTap
        self.isCheckboxSelectable = isCheckboxSelectable
        self.initiallySelected = initiallySelected
        self.onSelectionChange = onSelectionChange
        self.isActiveQueue = isActiveQueue
        self._isSelected = State(initialValue: initiallySelected)
        self.isActiveQueue = isActiveQueue 
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {

            HStack(alignment: .top, spacing: 16) {

                Image(image)
                    .resizable()
                    .aspectRatio(contentMode: .fill)
                    .frame(width: 80, height: 80)
                    .clipShape(Circle())

                VStack(alignment: .leading, spacing: 4) {

                    Text(title)
                        .font(.headline)
                        .fontWeight(.bold)

                    if !specialText.isEmpty {
                        Text(specialText)
                            .font(.subheadline)
                            .foregroundColor(Color(red: 0.3, green: 0.6, blue: 0.5))
                    }

                    if !detailLine1.isEmpty {
                        Text(detailLine1)
                            .font(.caption)
                            .foregroundColor(.secondary)
                    }

                    if !detailLine2.isEmpty {
                        Text(detailLine2)
                            .font(.caption)
                            .foregroundColor(.secondary)
                    }
                }

                Spacer()
            }

            if showExtraSection {
                Divider()

                HStack(alignment: .top) {

                    VStack(alignment: .leading, spacing: 4) {
                        if !bottomTitleLeft.isEmpty {
                            Text(bottomTitleLeft)
                                .font(.caption)
                                .bold()
                        }

                        ForEach(listItems, id: \.self) { item in
                            Text("• \(item)")
                                .font(.caption2)
                                .foregroundColor(.secondary)
                        }
                    }

                    Spacer()

                    VStack(alignment: .trailing, spacing: 4) {
                        if !bottomTitleRight.isEmpty {
                            Text(bottomTitleRight)
                                .font(.caption)
                                .bold()
                        }

                        if !bottomSubTextRight.isEmpty {
                            Text(bottomSubTextRight)
                                .font(.caption2)
                                .foregroundColor(.secondary)
                        }
                    }
                }
            }

            // ⚡ Always show action section at bottom, decide inside which buttons to show
            actionSection
                .frame(maxWidth: .infinity)
                .padding(.top, 12)
                .multilineTextAlignment(.center)
        }
        .padding(20)
        .background(Color.white)
        .cornerRadius(25)
        .overlay(
            RoundedRectangle(cornerRadius: 25)
                .stroke(
                    isSelected
                        ? Color(red: 0.28, green: 0.58, blue: 0.53)
                        : Color.gray.opacity(0.15),
                    lineWidth: isSelected ? 2 : 1
                )
        )
        .overlay(alignment: .topTrailing) {
            if isCheckboxSelectable && isSelected {
                Image(systemName: "checkmark.circle.fill")
                    .foregroundColor(Color(red: 0.28, green: 0.58, blue: 0.53))
                    .background(Circle().fill(Color.white))
                    .font(.system(size: 24))
                    .padding(12)
            }
        }
        .padding(.horizontal)
        .onTapGesture {
            if isCheckboxSelectable {
                isSelected.toggle()
                onSelectionChange?(isSelected)
            }
        }
    }

    private var actionSection: some View {
        Group {
            if isActiveQueue {
                HStack(spacing: 12) {
                   
                    Button(action: {
                        onButtonTap?()
                    }) {
                        Text("Start This Test")
                            .font(.system(size: 14, weight: .medium))
                            .foregroundColor(.white)
                            .padding(.vertical, 8)
                            .padding(.horizontal, 16)
                            .background(Color(red: 0.28, green: 0.58, blue: 0.53))
                            .cornerRadius(20)
                    }
                    .buttonStyle(.plain)

                    Button(action: {
                        onRescheduleTap?()
                    }) {
                        Text("Schedule Later")
                            .font(.system(size: 14, weight: .medium))
                            .foregroundColor(Color(red: 0.28, green: 0.58, blue: 0.53))
                            .padding(.vertical, 8)
                            .padding(.horizontal, 16)
                            .background(
                                RoundedRectangle(cornerRadius: 20)
                                    .stroke(Color(red: 0.28, green: 0.58, blue: 0.53), lineWidth: 1)
                            )
                    }
                    .buttonStyle(.plain)
                }
            } else {
                Button(action: {
                    onButtonTap?()
                }) {
                    Text("Fee: \(fee)")
                        .font(.system(size: 14, weight: .medium))
                        .foregroundColor(.white)
                        .padding(.vertical, 8)
                        .padding(.horizontal, 24)
                        .background(Color(red: 0.28, green: 0.58, blue: 0.53))
                        .cornerRadius(20)
                }
                .buttonStyle(.plain)
            }
        }
    }
}


struct BloodTestCard_Previews: PreviewProvider {
    static var previews: some View {
        ScrollView {
            VStack(spacing: 20) {

                BloodTestCard(
                    image: "doctor02",
                    title: "Blood Group & Rh",
                    specialText: "Selectable Component",
                    detailLine1: "Tap anywhere to select",
                    detailLine2: "Location: Room 02",
                    showExtraSection: false,
                    fee: "$25",
                    isCheckboxSelectable: true
                )
                
                
                BloodTestCard(
                    image: "doctor01",
                    title: "Kidney Function Test",
                    specialText: "Lab Test",
                    detailLine1: "Location: Main Lab - Level 1",
                    detailLine2: "",
                    showExtraSection: true,
                    bottomTitleLeft: "Requirements",
                    listItems: ["Avoid high-protein meals", "Hydration is key"],
                    bottomTitleRight: "Approximate Time",
                    bottomSubTextRight: "~12 min",
                    fee: "$48",
                    isActiveQueue: true
                )
            }
            .padding(.vertical)
        }
        .background(Color(white: 0.95))
    }
}
